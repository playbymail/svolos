<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How far through the game's calendar a game has got. Turn 0 is the setup turn — year 0,
     * quarter 0 — and every turn after it is one quarter, so turn 4 ends year 0 and turn 5 opens
     * year 1. The year and quarter are **derived** from this column by `App\Models\Game::yearAndQuarter()`
     * rather than stored beside it: three columns that have to agree are two chances to disagree.
     *
     * `0` is a real default rather than a stand-in for "unset". Every game genuinely is at turn 0
     * from the moment it is created, so the column is not nullable and nothing has to reserve a
     * value to mean absence — which is the same reasoning `..._add_seed_to_games_table.php` gives
     * for its own bounds.
     *
     * **Nothing in the application writes this column yet.** Turn processing and order resolution
     * live in the game engine, and the engine is not wired up here; this exists so a player's screen
     * can report where their game is rather than leaving a blank on it. Adding a writer means
     * deciding what advancing a turn means for a paused game and whether it can ever go backwards,
     * and neither question has an answer worth guessing at yet.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('turn')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('turn');
        });
    }
};
