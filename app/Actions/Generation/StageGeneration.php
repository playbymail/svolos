<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Models\GenerationRun;

/**
 * One stage of world generation, from a saved run to the rows it produces.
 *
 * The implementations are the seam between the pure generators in `App\Generation` — which know
 * nothing about models — and the database. Each one reads the seed off the run it is given, calls its
 * generator, writes the rows, and hands back the summary to store on the run.
 *
 * `RunGeneration` owns the transaction and the run bookkeeping, so an implementation only has to
 * write its own rows.
 */
interface StageGeneration
{
    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage;

    /**
     * Generate and persist this stage's rows for a run.
     *
     * @return array<string, mixed> the summary to store on the run
     */
    public function handle(GenerationRun $run): array;

    /**
     * Delete the rows a run produced, leaving the run itself standing.
     *
     * Called when a gamemaster regenerates past a pending run. The stage knows what it wrote, so it is
     * the stage that throws it away — `RunGeneration` stays free of any per-stage branching.
     */
    public function discard(GenerationRun $run): void;
}
