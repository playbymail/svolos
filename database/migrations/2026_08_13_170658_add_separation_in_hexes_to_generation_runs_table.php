<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which of two distances `minimum_separation` beside it is counted in.
     *
     * **Unset — the default — means Euclidean**: the straight line through all three dimensions, the
     * same measure `ClusterGenerator::MINIMUM_SEPARATION` compares. Set, it means hexes on the plane
     * the map draws, which ignores height entirely.
     *
     * They are genuinely different questions rather than two scales of one, which is why this is a
     * choice rather than a conversion. Two systems sharing a column are the **same hex** however far
     * apart they are vertically — thirty units, at the extreme — so a hex separation is about reach on
     * the map, while a Euclidean one is about distance through space. A pair that satisfies one can
     * plainly fail the other.
     *
     * It sits on the run beside `traveler` and `minimum_separation` for the same reason they do: it is
     * an input to *this* attempt, so the accepted run records how the game's homes were actually
     * spread. Only the home stellia stage reads it; every other run stores false and ignores it, which
     * is why no validation rule ties it to a stage.
     *
     * Not nullable, and `false` is the honest value for the rows that already exist: they are runs of
     * stages that never consulted it.
     */
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->boolean('separation_in_hexes')->default(false)->after('minimum_separation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('separation_in_hexes');
        });
    }
};
