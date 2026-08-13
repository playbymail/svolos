<?php

use App\Enums\GenerationStage;
use App\Models\GenerationRun;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A **data** migration, because in this one place the stored data is user-visible text.
     *
     * `GenerationStageCard` prints a run summary's keys verbatim — that is the whole design, and it is
     * why the planets stage's `types` needs no special case — so the key `stelliums` written by
     * `StelliumPlan::summary()` was itself the label on the screen. Renaming it to `stellia` fixes new
     * runs and nothing else: an accepted run's summary is written once and never recomputed, so every
     * game generated before this would have gone on showing the old word beside stages that had
     * changed to the new one.
     *
     * The alternative was to teach the card to relabel that key, and it is worse: `summaryEntries()`
     * names exactly one special case (`mix`, whose keys are bare numbers and need a noun), and a second
     * entry for a word that is simply out of date would make the renderer the place where spelling is
     * decided.
     *
     * **It loops over the rows**, the way the seed backfill does: `summary` is a JSON column and the
     * key sits inside it, so rewriting it in SQL would need a different JSON function per driver for
     * something that runs against a handful of rows.
     */
    public function up(): void
    {
        $this->rename('stelliums', 'stellia');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rename('stellia', 'stelliums');
    }

    /**
     * Move one key of every stellia run's stored summary, leaving the rest of it untouched.
     *
     * Superseded runs are included: their artefacts are gone but the row is the record of what was
     * tried, and the screen lists them.
     */
    private function rename(string $from, string $to): void
    {
        GenerationRun::query()
            ->where('stage', GenerationStage::Stelliums)
            ->whereNotNull('summary')
            ->each(function (GenerationRun $run) use ($from, $to): void {
                $summary = $run->summary;

                if (! is_array($summary) || ! array_key_exists($from, $summary)) {
                    return;
                }

                /* Rebuilt in order rather than appended to, so the count stays the first chip shown. */
                $renamed = [];

                foreach ($summary as $key => $value) {
                    $renamed[$key === $from ? $to : $key] = $value;
                }

                $run->summary = $renamed;
                $run->save();
            });
    }
};
