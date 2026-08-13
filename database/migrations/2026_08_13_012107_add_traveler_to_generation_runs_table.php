<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The second thing a gamemaster chooses about a run, after its seed. With `traveler` set, the
     * cluster generator refuses any point sharing an `(x, y)` pair with one already placed, so every
     * system lands in a hex of its own instead of the usual handful stacking.
     *
     * It belongs on the run rather than on the game because it is an input to *this* attempt: a
     * gamemaster can try one seed both ways, and the accepted run is then the permanent record of
     * which cluster the game actually got. That is the same reason `seed` lives here.
     *
     * Only the cluster stage reads it — a stelliums or planets run stores what was asked and ignores
     * it — and it is deliberately not nullable: existing rows were drawn without the constraint, and
     * `false` is exactly what they were.
     */
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->boolean('traveler')->default(false)->after('seed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('traveler');
        });
    }
};
