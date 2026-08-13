<?php

use App\Generation\HomeStelliumGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The third thing a gamemaster chooses about a run, and the exact counterpart of `traveler` beside
     * it: how far apart two home stellia must stand.
     *
     * It belongs on the run for the same reason the seed and the traveler flag do — it is an input to
     * *this* attempt, so the accepted run is the permanent record of the separation the game actually
     * got, and the form starts from it so trying again keeps the value the last attempt used instead of
     * silently reverting to the default.
     *
     * Only the home stellia stage reads it; a cluster or planets run stores the default and ignores it,
     * which is why no validation rule ties the field to a stage. As with `traveler`, no value of it is
     * ever *wrong* — it is simply irrelevant to three stages out of four.
     *
     * **It is a bare number until `separation_in_hexes` beside it says what it counts** — a
     * straight-line distance through all three dimensions by default, or steps on the hex map. The two
     * columns are a pair and neither means anything alone, which is why every label that prints the
     * number prints the unit with it. See the migration that adds the second, and
     * `App\Generation\HomeStelliumGenerator`.
     *
     * Not nullable, and defaulted rather than backfilled: rows that already exist were runs of stages
     * that never consulted it, so the default is not a claim about them — it is the absence of one.
     */
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('minimum_separation')
                ->default(HomeStelliumGenerator::DEFAULT_MINIMUM_SEPARATION)
                ->after('traveler');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('minimum_separation');
        });
    }
};
