<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GenerationRun;

/**
 * Marks a stage's pending run as the one that produced the game.
 *
 * Accepting writes a timestamp and nothing else. The rows the run produced are already there — they
 * were written when it ran, because a gamemaster reviewing a cluster has to be able to look at it —
 * so acceptance is a decision about them rather than a second act of generation.
 *
 * What acceptance costs is the ability to regenerate: `RunGeneration` will not supersede an accepted
 * run, so from here the only way back is starting the whole generation over. That is the deliberate
 * trade — the next stage builds on this one, and quietly rewriting a cluster that stelliums already
 * sit on would leave a game describing a world that never existed.
 */
class AcceptGeneration
{
    /**
     * Accept the pending run for a stage.
     */
    public function handle(Game $game, GenerationStage $stage): GenerationRun
    {
        $game->load('generationRuns');

        $run = $game->generationRunFor($stage);

        /*
         * The caller has already refused anything but a stage under review — see
         * `Gamemaster\GenerationController::accept()` — so this is the last line of defence rather
         * than the check. It stays because an action that quietly accepted nothing would be worse.
         */
        abort_if($run === null || ! $run->isPending(), 403);

        $run->accepted_at = now();
        $run->save();

        $game->load('generationRuns');

        return $run;
    }
}
