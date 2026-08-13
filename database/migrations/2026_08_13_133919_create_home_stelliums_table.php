<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where each player's faction begins: one row per player per generation run, pairing a seat to a
     * location in the game's cluster.
     *
     * ## The run owns the arrangement, not the seat
     *
     * The obvious shape is a `home_location_id` column on `game_seats`, and it is the wrong one. A seat
     * is roster — it outlives every world the game generates, and retiring one is a fact about a person
     * rather than about a cluster. An arrangement of homes is a **generated artefact**, exactly like the
     * stelliums and the planets, so it hangs off the run that produced it and inherits the whole
     * machine: regenerating discards it through `GenerateHomeStellia::discard()`, and starting the
     * generation over deletes every run and takes it with them. Neither of those is code anybody has to
     * remember to write.
     *
     * Both unique keys are scoped to the run rather than to the game — one home per player and one
     * player per system, *within an arrangement* — which is what lets a fresh attempt be written beside
     * the pending one it supersedes instead of colliding with it.
     *
     * ## It points at a location, not at a stellium
     *
     * The coordinates the roster prints and the hex distance the placement rule measures both live on
     * `locations`; a stellium has neither. "Single star" is a constraint the generator *draws under* —
     * it is handed only single-star systems to choose from — rather than something a foreign key could
     * express, so pointing at the stellium would buy nothing and cost a join everywhere this is read.
     *
     * ## The table name is spelled out, for the reason `stelliums` is
     *
     * Laravel's inflector pluralises `HomeStellium` to `home_stellia` — the `medium`/`media` rule — so
     * `App\Models\HomeStellium` sets `protected $table = 'home_stelliums'` and a foreign key pointed
     * here would have to name it. `tests/Feature/GenerationModelTest.php` asserts the hazard and the
     * override together, so the day an upstream fix makes the override unnecessary somebody removes it
     * deliberately.
     *
     * There is no `game_id`, for the reason `planets` has none: every uniqueness guarantee here is
     * about the run, so the column could only duplicate a path already walkable through it.
     */
    public function up(): void
    {
        Schema::create('home_stelliums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['generation_run_id', 'game_seat_id']);
            $table->unique(['generation_run_id', 'location_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_stelliums');
    }
};
