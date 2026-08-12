<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
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
     */
    public function handle(Game $game, GenerationStage $stage, int $seed): GenerationRun
    {
        return DB::transaction(function () use ($game, $stage, $seed): GenerationRun {
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
            $run->attempt = $game->nextGenerationAttemptFor($stage);
            $run->save();

            $run->summary = $generation->handle($run);
            $run->save();

            $game->load('generationRuns');

            return $run;
        });
    }
}
