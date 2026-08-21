<?php

namespace App\Concerns;

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Models\Entity;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Planet;
use App\Models\Star;
use App\Models\Unit;

/**
 * Shapes a game's generation for a screen.
 *
 * Shared by the gamemaster's screen, which drives the generators, and the administrator's, which only
 * watches. They differ by one flag: only the gamemaster's payload carries the suggested seeds and the
 * controls that go with them. Everything else — where each stage stands, the seed behind it, the seeds
 * that were tried and dropped — is the same on both, because it is the same question being asked.
 *
 * Every state here is derived from the runs by `Game`. Nothing in the payload is a decision this
 * presenter makes, and nothing in it is authoritative: `Gamemaster\GenerationController` refuses the
 * same things again with a 403 whatever the screen renders.
 */
trait PresentsGeneration
{
    /**
     * Shape every stage of a game's generation, in the order they happen.
     *
     * @return array{
     *     is_complete: bool,
     *     can_generate: bool,
     *     can_start_over: bool,
     *     stages: array<int, array<string, mixed>>,
     * }
     */
    protected function presentGeneration(Game $game, bool $withSuggestions = false): array
    {
        $game->loadMissing('generationRuns');

        $inSetup = $game->status === GameStatus::Setup;

        return [
            'is_complete' => $game->isGenerationComplete(),
            /* Generation happens during setup and nowhere else, whatever a stage's own state says. */
            'can_generate' => $inSetup,
            'can_start_over' => $inSetup && $game->hasGenerationRuns(),
            'stages' => array_map(
                fn (GenerationStage $stage): array => $this->presentStage($game, $stage, $withSuggestions),
                GenerationStage::cases(),
            ),
        ];
    }

    /**
     * Say whether a game's base seed may still be edited, and if not, why not.
     *
     * There are two reasons a seed closes and they are **not** interchangeable, which is why the reason
     * is a sentence from the server rather than something the screen infers from the status: a game in
     * setup whose cluster has been generated is locked for a completely different reason than a game
     * that has started, and telling somebody "the game has left setup" about a game that plainly has
     * not is worse than saying nothing.
     *
     * The order matches `GameValidationRules::gameSeedRules()` — status first, then generation — so the
     * sentence on the screen is always the message the endpoint would answer with.
     *
     * @return array{can_change_seed: bool, seed_lock_reason: string|null}
     */
    protected function presentSeedLock(Game $game): array
    {
        $game->loadMissing('generationRuns');

        if ($game->status !== GameStatus::Setup) {
            return [
                'can_change_seed' => false,
                'seed_lock_reason' => __('The game has left setup, so its seed is fixed. Everything it has generated was drawn from this number, and changing it now would describe a run that never happened.'),
            ];
        }

        if ($game->hasGenerationRuns()) {
            return [
                'can_change_seed' => false,
                'seed_lock_reason' => __('This seed has already been generated from. Start the generation over to change it.'),
            ];
        }

        return ['can_change_seed' => true, 'seed_lock_reason' => null];
    }

    /**
     * Name the empire holding a seat, for a screen that is looking at the game world.
     *
     * **Empires are named by their empire name everywhere inside a game**, never by the account behind
     * them. That is not only the player's screen being polite about privacy — it is the same rule on
     * the gamemaster's and the administrator's, where the roster on the very same page is what answers
     * "which account is this". Two names for one empire on one screen is the thing to avoid, and it is
     * exactly what shipped for a moment when only the player's map had been converted.
     *
     * The game is attached rather than lazy-loaded because `GameSeat::defaultEmpireName()` reads its
     * short name, and every caller here already has the game in hand — the alternative is a query per
     * placed seat for a string the caller is holding.
     */
    private function empireNameFor(?GameSeat $seat, Game $game): ?string
    {
        if ($seat === null) {
            return null;
        }

        $seat->setRelation('game', $game);

        return $seat->empireName();
    }

    /**
     * Shape the locations a game's cluster is made of, with their stelliums if it has any yet.
     *
     * Returns an empty list until a cluster run exists, so a screen with nothing to show is given
     * nothing rather than a shape to interpret.
     *
     * **The two counts are null for different reasons, which is why they are computed differently.**
     * `star_count` is null exactly when there is no stellium row yet — the relation is missing, so the
     * nullsafe read answers it. A stellium *exists* before its planets do, so `planets_count` comes
     * back as a genuine `0` in the state that means "not decided yet", and reading that zero as null
     * would only work by accident: it happens that every star gets at least one planet, so zero is
     * unreachable afterwards. Gate it on the run instead, which is what actually distinguishes the two
     * states. Both nulls mean "not decided yet"; neither means "empty".
     *
     * Two queries whatever the size of the cluster: the locations, and their stelliums with a count of
     * stars and of planets each. The planets count goes through `Stellium::planets()`, which reaches
     * them through the stars, so this does not walk 141 stelliums to add up their own.
     *
     * @return array<int, array{
     *     id: int,
     *     ordinal: int,
     *     x: int,
     *     y: int,
     *     z: int,
     *     radius: float,
     *     star_count: int|null,
     *     planet_count: int|null,
     *     home_seat_id: int|null,
     *     home_player_name: string|null,
     * }>
     */
    protected function presentLocations(Game $game): array
    {
        $game->loadMissing('generationRuns');

        $planetsGenerated = $game->generationRunFor(GenerationStage::Planets) !== null;

        return $game->locations()
            /*
             * The home is eager-loaded through to the account, because the map names whose it is rather
             * than only marking it — "somebody starts here" is not the useful half. Three levels deep
             * and still one query per level, against a hundred locations.
             */
            ->with([
                'stellium' => fn ($query) => $query->withCount(['stars', 'planets']),
                'homeStellium.gameSeat',
            ])
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'ordinal' => $location->ordinal,
                'x' => $location->x,
                'y' => $location->y,
                'z' => $location->z,
                'radius' => round($location->radius(), 2),
                'star_count' => $location->stellium?->stars_count,
                'planet_count' => $planetsGenerated ? $location->stellium?->planets_count : null,
                /*
                 * Null here means "nobody begins at this system", which — unlike the two counts above —
                 * is the ordinary answer rather than a stage that has not run: some ninety-odd
                 * locations are nobody's home even after the stage is accepted.
                 */
                'home_seat_id' => $location->homeStellium?->game_seat_id,
                'home_player_name' => $this->empireNameFor($location->homeStellium?->gameSeat, $game),
            ])
            ->values()
            ->all();
    }

    /**
     * Shape one location's stars and the planets around them, for the row the screen has expanded.
     *
     * This is the review surface for the planets stage. The cluster ships whole because a hundred rows
     * of four small numbers are cheaper than the request that would fetch them; some 775 planets of
     * eight fields are not, and reviewing a seed does not mean reading all of them — it means looking
     * at a system or two. So this is asked for a location at a time, through an optional prop that only
     * runs on a partial reload, rather than riding along on every render of the screen.
     *
     * Returns null when no location is asked for, and when the one asked for belongs to another game —
     * the scoping is the same rule `Route::scopeBindings()` enforces on seat routes, done by hand here
     * because the location arrives as a query parameter rather than as a route parameter.
     *
     * **It is also the review surface for the units stage**, which is why each planet carries the
     * entities standing at it and what each of those holds. Nothing else on the screen could show it:
     * the cluster table has a row per system rather than per world, and only a handful of the hundred
     * locations have anybody at them. It rides here rather than in a panel of its own because "what is
     * at this system" and "what is orbiting in it" are one question asked once.
     *
     * @return array{
     *     id: int,
     *     ordinal: int,
     *     stars: array<int, array{
     *         id: int,
     *         label: string,
     *         planets: array<int, array<string, mixed>>,
     *     }>,
     * }|null
     */
    protected function presentLocationDetail(Game $game, ?int $locationId): ?array
    {
        if ($locationId === null) {
            return null;
        }

        $location = $game->locations()
            ->whereKey($locationId)
            /*
             * Four levels for the planets and three more past them for what is standing there. Deep,
             * and still one query per level against a single system — the alternative is a lazy load
             * per planet, which is ten queries for a system nobody has settled.
             */
            ->with(['stellium.stars.planets.entities.gameSeat', 'stellium.stars.planets.entities.units'])
            ->first();

        if ($location?->stellium === null) {
            return null;
        }

        return [
            'id' => $location->id,
            'ordinal' => $location->ordinal,
            'stars' => $location->stellium->stars
                ->map(fn (Star $star): array => [
                    'id' => $star->id,
                    /* A star is named by its place in the stellium: 1 is A, and there are never more than four. */
                    'label' => $star->label(),
                    'planets' => $star->planets
                        ->map(fn (Planet $planet): array => [
                            'id' => $planet->id,
                            'ordinal' => $planet->ordinal,
                            'type' => $planet->type->value,
                            'type_label' => $planet->type->label(),
                            'habitability' => $planet->habitability,
                            'fuel' => $planet->fuel,
                            'metals' => $planet->metals,
                            'minerals' => $planet->minerals,
                            'entities' => $this->presentEntities($planet, $game),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Shape what is standing at one planet, and what each of those things holds.
     *
     * The name rides along for the reason the cluster map carries it rather than only marking a home:
     * "somebody is here" is not the useful half. It reaches through the seat rather than through the
     * account, because control is a seat — an entity belongs to a place at a game, not to a person
     * across all of them — and it is the **empire's** name for the reason `empireNameFor()` gives:
     * inside a game an empire is named by its empire name, on every screen that shows one.
     *
     * Units are ordered by inventory, then by kind, then by technology level **highest first**, so
     * the same entity reads the same way every time, the components — the part that says what the
     * thing *is* — come first, and a kind held at several levels leads with the best of them. The
     * level has to be in the ordering at all because it is part of the row's identity: `LSTR-10` and
     * `LSTR-2` in one hold are two rows of the same kind, and without it their order is whatever the
     * database felt like.
     *
     * **One closure returning a tuple, never an array of closures.** `sortBy([$a, $b])` looks like two
     * key extractors and is not: given an array of comparisons, Laravel calls a callable one as a full
     * comparator, `$prop($a, $b)`. A single-parameter closure then silently takes the first argument,
     * ignores the second and returns a position as though it were a comparison result — which sorts
     * nothing and interleaves the inventories. That is invisible here and fatal one file away, because
     * `LocationSystemPanel` groups the list it is handed.
     *
     * @return array<int, array{
     *     id: int,
     *     type: string,
     *     type_label: string,
     *     seat_id: int,
     *     player_name: string,
     *     units: array<int, array<string, mixed>>,
     * }>
     */
    private function presentEntities(Planet $planet, Game $game): array
    {
        return $planet->entities
            ->map(fn (Entity $entity): array => [
                'id' => $entity->id,
                'type' => $entity->type->value,
                'type_label' => $entity->type->label(),
                'seat_id' => $entity->game_seat_id,
                'player_name' => (string) $this->empireNameFor($entity->gameSeat, $game),
                'units' => $entity->units
                    ->sortBy(fn (Unit $unit): array => [
                        (int) array_search($unit->inventory, Inventory::cases(), true),
                        (int) array_search($unit->type, UnitType::cases(), true),
                        -$unit->technology_level,
                    ])
                    ->map(fn (Unit $unit): array => [
                        'id' => $unit->id,
                        'type' => $unit->type->value,
                        'type_label' => $unit->type->label(),
                        'inventory' => $unit->inventory->value,
                        'assignment_label' => $unit->inventory->label(),
                        'technology_level' => $unit->technology_level,
                        'quantity' => $unit->quantity,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Shape one stage: where it stands, what produced it, and what was tried before.
     *
     * `suggested_seed` is what the seed box starts with, computed on every render rather than stored,
     * and only for the screen that can act on it. Three cases, and the third is easy to lose:
     *
     * - **no run yet** — the game's own base seed, since that is the number somebody chose for this
     *   game;
     * - **a regeneration** — a fresh random number, because regenerating with the seed already on the
     *   pending run would redraw the same thing and is refused;
     * - **a regeneration of the home stellia** — the pending run's **own** seed, unchanged. That stage
     *   folds the attempt into its stream, so the same seed genuinely produces a different arrangement
     *   and the refusal above does not apply to it. Suggesting a new number there would contradict the
     *   form beside it, which says the same seed is fine and labels the button "try another
     *   arrangement" — and it would quietly change the world the arrangement is drawn from, which is
     *   the opposite of what the gamemaster asked for.
     *
     * `traveler` does double duty and is one field for that reason: it labels the run under review,
     * and it is what the cluster form's checkbox starts ticked from, so regenerating a stage keeps the
     * mode the last attempt used instead of silently reverting to the ordinary draw. It is null before
     * any run, which the screen reads as unticked — there is nothing to inherit yet.
     *
     * `minimum_separation` and `separation_in_hexes` are the home stellia stage's copy of exactly that,
     * and read null the same way — the form then falls back to the generator's default and to the
     * Euclidean measure. They travel as a **pair**, because the number means nothing without the unit:
     * a screen that showed one without the other would be reporting "5" and letting the reader guess.
     *
     * All of them are sent for every stage rather than only the one that uses them: a run stores what
     * it was asked, and a screen that had to know which fields applied to which stage would be a second
     * copy of the registry.
     *
     * @return array<string, mixed>
     */
    private function presentStage(Game $game, GenerationStage $stage, bool $withSuggestions): array
    {
        $state = $game->generationStateFor($stage);
        $run = $game->generationRunFor($stage);

        $presented = [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'description' => $stage->description(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'seed' => $run?->seed,
            'traveler' => $run?->traveler,
            'minimum_separation' => $run?->minimum_separation,
            'separation_in_hexes' => $run?->separation_in_hexes,
            'attempt' => $run?->attempt,
            'summary' => $run?->summary,
            'generated_at_diff' => $run?->created_at?->diffForHumans(),
            'accepted_at_diff' => $run?->accepted_at?->diffForHumans(),
            'history' => $this->presentHistory($game, $stage),
        ];

        if (! $withSuggestions) {
            return $presented;
        }

        return [
            ...$presented,
            'suggested_seed' => match (true) {
                $run === null => $game->seed,
                $stage === GenerationStage::HomeStellia => $run->seed,
                default => Game::randomSeed(),
            },
        ];
    }

    /**
     * Shape the runs of a stage that were regenerated past.
     *
     * These are the rows with nothing left to show for them, and they are in the payload precisely
     * because of that: what a gamemaster asked for is the one thing that survives the attempt, and
     * seeing the list is how somebody works out what has already been tried.
     *
     * `file` is that same thing for a home template read from a document — the run kept it for the
     * reason it kept the seed. It rides beside the seed rather than replacing it because a run has
     * both, and null on every other stage is the ordinary answer: nothing was uploaded.
     *
     * @return array<int, array{attempt: int, seed: int, file: string|null, generated_at_diff: string|null}>
     */
    private function presentHistory(Game $game, GenerationStage $stage): array
    {
        return $game->generationRuns
            ->filter(fn (GenerationRun $run): bool => $run->stage === $stage && $run->superseded_at !== null)
            ->map(fn (GenerationRun $run): array => [
                'attempt' => $run->attempt,
                'seed' => $run->seed,
                'file' => $run->template['file'] ?? null,
                'generated_at_diff' => $run->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
