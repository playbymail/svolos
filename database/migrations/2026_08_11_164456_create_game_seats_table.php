<?php

use App\Enums\GameRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A seat is the join between an account and one game, and it carries the game role that account
     * holds *there*. `role` is `player` by default because that is what most seats are, and because a
     * default that grants the lesser role is the right way round for a column that can be omitted.
     *
     * **The unique index on `(game_id, user_id)` counts retired seats.** That is the point of it, not
     * an accident of leaving `is_active` out of the key: seats are retired (`is_active = false`),
     * never deleted, because engine history keeps referring to them, so an account that once had a
     * seat still has that row. Bringing them back is therefore a *reactivation* of the one row, never
     * a second row, and the index makes a second row impossible at the database level rather than only
     * in a validation rule. See `.ai/rules/games.md`.
     *
     * Both foreign keys cascade. Deleting a game takes its seats with it, which is why the delete
     * confirmation on the games screen names how many there are; deleting an account takes its seats
     * too, because a seat with no account is not history anybody can read.
     */
    public function up(): void
    {
        Schema::create('game_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default(GameRole::Player->value);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_seats');
    }
};
