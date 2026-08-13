<?php

namespace App\Concerns;

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Planet;
use App\Models\Star;

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
     * }>
     */
    protected function presentLocations(Game $game): array
    {
        $game->loadMissing('generationRuns');

        $planetsGenerated = $game->generationRunFor(GenerationStage::Planets) !== null;

        return $game->locations()
            ->with(['stellium' => fn ($query) => $query->withCount(['stars', 'planets'])])
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
            ->with(['stellium.stars.planets'])
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
                    'label' => chr(ord('A') + $star->ordinal - 1),
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
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Shape one stage: where it stands, what produced it, and what was tried before.
     *
     * `suggested_seed` is a fresh draw on every render rather than a stored value, and only for the
     * screen that can act on it. It is what the seed box starts with: the game's own base seed for a
     * first run, since that is the number the administrator or gamemaster chose for this game, and a
     * new random one for a regeneration, since regenerating with the same seed would redraw the same
     * thing and is refused.
     *
     * `traveler` does double duty and is one field for that reason: it labels the run under review,
     * and it is what the cluster form's checkbox starts ticked from, so regenerating a stage keeps the
     * mode the last attempt used instead of silently reverting to the ordinary draw. It is null before
     * any run, which the screen reads as unticked — there is nothing to inherit yet.
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
            'suggested_seed' => $run === null ? $game->seed : Game::randomSeed(),
        ];
    }

    /**
     * Shape the runs of a stage that were regenerated past.
     *
     * These are the rows with nothing left to show for them, and they are in the payload precisely
     * because of that: the seed a gamemaster rejected is the one thing that survives the attempt, and
     * seeing the list is how somebody works out what has already been tried.
     *
     * @return array<int, array{attempt: int, seed: int, generated_at_diff: string|null}>
     */
    private function presentHistory(Game $game, GenerationStage $stage): array
    {
        return $game->generationRuns
            ->filter(fn (GenerationRun $run): bool => $run->stage === $stage && $run->superseded_at !== null)
            ->map(fn (GenerationRun $run): array => [
                'attempt' => $run->attempt,
                'seed' => $run->seed,
                'generated_at_diff' => $run->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
