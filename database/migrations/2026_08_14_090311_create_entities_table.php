<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The things in this game that accept orders: one row per colony and per ship, each controlled by
     * one seat and standing at one planet.
     *
     * ## Control is a seat, and there is no arc
     *
     * `.ai/rules/agents.md` settled this before the table existed. Earlier versions of this game
     * modelled control as `(Player | Agent) --o< Entity`: two nullable foreign keys and a check
     * constraint saying exactly one is set. That arc existed only because Player and Agent were
     * separate tables, and here they are not — an agent is a `User` with `is_agent` set, holding an
     * ordinary `GameSeat`. So control is **one non-nullable `game_seat_id`**, with no second key and
     * nothing to constrain.
     *
     * A seat is also the right grain rather than merely the convenient one: control is per-game, a
     * `User` spans games, and seats are retired rather than deleted precisely so that engine history
     * can go on naming them. An entity therefore outlives its player leaving the game.
     *
     * ## `generation_run_id` is nullable, and the null is the point
     *
     * Every other generated table hangs off its run with a non-nullable key, because a location or a
     * planet has no meaning apart from the run that drew it. Entities are the first thing here that is
     * not purely an artefact: these were placed by the assets stage, but a ship built during play will
     * have been placed by no run at all. The nullable column is what distinguishes the two, and it
     * costs nothing — `GenerateAssets::discard()` still deletes `$run->entities()`, and starting the
     * generation over still takes them by cascade.
     *
     * Nothing built in play can be lost that way: a game cannot leave setup until its generation is
     * complete, and `Gamemaster\GenerationController::restart()` refuses any game that has.
     *
     * ## Position is a planet, for both kinds
     *
     * A colony is assembled on the home world; the ship is in orbit above the same planet, and `type`
     * is what says which. One column serves both, and it is **not nullable** — deep space and a ship
     * mid-jump are not modelled, and a nullable column would be advertising a state nothing can
     * produce and every reader would have to handle.
     *
     * There is no `game_id`, for the reason `planets` and `home_stelliums` have none: the seat already
     * belongs to exactly one game, so the column could only duplicate a path already walkable.
     */
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamps();

            $table->index(['game_seat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
