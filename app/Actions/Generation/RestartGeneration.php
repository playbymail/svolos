<?php

namespace App\Actions\Generation;

use App\Models\Game;

/**
 * Throws away everything a game's generators have produced, back to an empty cluster.
 *
 * This is the **only** way past an accepted stage, and it is deliberately all-or-nothing rather than a
 * rewind of one step. A cluster and the stelliums standing on it are one world: putting the cluster
 * back into review while its stelliums survived would leave stars at locations that no longer exist,
 * and a per-stage rewind would have to encode which stages depend on which — a second copy of the
 * ordering that `GenerationStage` already carries.
 *
 * Deleting the runs is enough to delete the world: `locations.generation_run_id` and
 * `stelliums.generation_run_id` both cascade, `stars.stellium_id` cascades behind them, so one delete
 * takes the whole chain. The attempt history goes too, which is the price of the reset — a game that
 * has started over is a game whose earlier seeds produced nothing that still exists.
 *
 * The game's base seed becomes editable again as a result, since `Game::hasGenerationRuns()` is what
 * closes it.
 */
class RestartGeneration
{
    /**
     * Delete every generation run for a game, and with it everything they produced.
     */
    public function handle(Game $game): void
    {
        $game->generationRuns()->delete();

        $game->load('generationRuns');
    }
}
