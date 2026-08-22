<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\HomeStelliumGenerator;
use App\Models\Game;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\DB;

/**
 * Runs one stage of generation for a game and records the attempt.
 *
 * Every generate and regenerate goes through here, which is what keeps the run bookkeeping in one
 * place:
 *
 * - a **pending** run for the same stage is superseded rather than deleted. Its row keeps the seed it
 *   used — the record of what was tried is the reason runs are stored at all — while the rows it
 *   produced are discarded, because only one cluster can be the game's at a time;
 * - an **accepted** run is never touched. Regenerating past one is not possible by this route: the
 *   only way back is `RestartGeneration`, which throws the whole world away deliberately;
 * - `attempt` counts every run of the stage, superseded ones included, so "attempt 3" keeps meaning
 *   the third time somebody asked.
 *
 * The whole thing is one transaction. A generator that throws — `GenerationFailed`, or anything a
 * bulk insert can raise — must leave the game exactly as it was, rather than with a run row claiming
 * a cluster that was never written.
 *
 * `$game->load('generationRuns')` runs first because the derived helpers on `Game` read the loaded
 * collection: acting on a collection loaded before this request would be reading the past.
 */
class RunGeneration
{
    public function __construct(private readonly StageGenerationRegistry $registry) {}

    /**
     * Generate a stage for a game from a seed, replacing any pending attempt at it.
     *
     * `$traveler`, `$minimumSeparation`, `$separationInHexes` and `$template` are stored on the run
     * beside the seed, and each is read by exactly one stage — the cluster draws one system per hex
     * when the first is set, the home stellia stand at least the second apart, counted in hexes when
     * the third says so and as a straight-line distance otherwise, and the fourth is the home system
     * every player begins in. All four are recorded rather than acted on here for the same reason the
     * seed is: the run is the record of what was asked, and the stage is what knows what to do with
     * it. A run of a stage that ignores one still stores it, which is why none of them has a
     * validation rule tying it to a stage.
     *
     * Two of them can be **absent rather than merely irrelevant**: on a home stellia
     * template run, null means "nothing was uploaded, so draw one", and `GenerateHomeTemplate` fills
     * it in. It arrives already parsed because a document that cannot be read should not produce a run
     * at all.
     *
     * `$kit` is the second of those, one stage further on: on a units run, null means "nothing was
     * chosen or uploaded, so draw one", and `GenerateUnits` fills it in. Like `$template` it arrives
     * already parsed, whether it came from an uploaded document or from a row in the gamemaster's
     * library — by the time it reaches here it is a document rather than a reference to one, which is
     * what keeps a run a record of what it was actually given.
     *
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>|null  $kit
     */
    public function handle(
        Game $game,
        GenerationStage $stage,
        int $seed,
        bool $traveler = false,
        int $minimumSeparation = HomeStelliumGenerator::DEFAULT_MINIMUM_SEPARATION,
        bool $separationInHexes = false,
        ?array $template = null,
        ?array $kit = null,
    ): GenerationRun {
        return DB::transaction(function () use ($game, $stage, $seed, $traveler, $minimumSeparation, $separationInHexes, $template, $kit): GenerationRun {
            $game->load('generationRuns');

            $generation = $this->registry->for($stage);
            $previous = $game->generationRunFor($stage);

            if ($previous !== null && $previous->isPending()) {
                $generation->discard($previous);

                $previous->superseded_at = now();
                $previous->save();
            }

            $run = new GenerationRun;
            $run->game_id = $game->getKey();
            $run->stage = $stage;
            $run->seed = $seed;
            $run->traveler = $traveler;
            $run->minimum_separation = $minimumSeparation;
            $run->separation_in_hexes = $separationInHexes;
            $run->template = $template;
            $run->kit = $kit;
            $run->attempt = $game->nextGenerationAttemptFor($stage);
            $run->save();

            $run->summary = $generation->handle($run);
            $run->save();

            $game->load('generationRuns');

            return $run;
        });
    }
}
