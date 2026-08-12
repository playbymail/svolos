<?php

namespace App\Concerns;

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GenerationRun;
use App\Models\Location;

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
     * Shape the locations a game's cluster is made of, with their stelliums if it has any yet.
     *
     * Returns an empty list until a cluster run exists, so a screen with nothing to show is given
     * nothing rather than a shape to interpret. `star_count` is null while the stelliums have not been
     * generated, which is the difference between "no stars here" and "not decided yet".
     *
     * Two queries whatever the size of the cluster: the locations, and their stelliums with a count of
     * stars each.
     *
     * @return array<int, array{
     *     id: int,
     *     ordinal: int,
     *     x: int,
     *     y: int,
     *     z: int,
     *     radius: float,
     *     star_count: int|null,
     * }>
     */
    protected function presentLocations(Game $game): array
    {
        return $game->locations()
            ->with(['stellium' => fn ($query) => $query->withCount('stars')])
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'ordinal' => $location->ordinal,
                'x' => $location->x,
                'y' => $location->y,
                'z' => $location->z,
                'radius' => round($location->radius(), 2),
                'star_count' => $location->stellium?->stars_count,
            ])
            ->values()
            ->all();
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
