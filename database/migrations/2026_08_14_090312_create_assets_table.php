<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What an entity owns: a quantity of one kind of unit, in one inventory.
     *
     * ## A quantity per kind, which the unique key is what makes true
     *
     * Fuel, food and ore are bulk, and a depot holding a thousand tonnes of food is one row rather
     * than a thousand. `(entity_id, type, inventory)` is unique so that there is exactly one answer
     * to "how much of this does it have, here" — two rows for the same kind in the same inventory
     * would be two answers, and every count in the game would then have to remember to sum rather
     * than read.
     *
     * The cost of the shape is that individual units cannot differ from one another: no condition, no
     * damage, no name. Nothing in the rules asks that yet, and the day something does it wants a
     * second table rather than the exploding of this one.
     *
     * ## `inventory` is a column because somebody decided it
     *
     * A crated mine and a working mine are the same kind of thing in two states, and moving between
     * them is an act. That makes it stored, the way `games.status` is — unlike `planets.zone`, which
     * has no column because it is a function of two others and could only ever disagree with them.
     * Which inventories a kind may legally sit in is a rule on `App\Enums\UnitType`, enforced by
     * `App\Generation\UnitHolding`'s constructor rather than by a check constraint.
     *
     * ## The cascade has to be the database's, not the model's
     *
     * `GenerateUnits::discard()` mass-deletes entities, and a mass delete fires no model events — so
     * an `deleting` hook here would never run and the units would be orphaned. Cascading in the
     * schema is the same reason `stars` cascades from `stelliums`.
     *
     * `quantity` is an unsigned integer rather than a smaller type: a colony's stores are already in
     * the thousands on turn one, and the numbers a working economy reaches are not something to have
     * to migrate for.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('assignment');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['entity_id', 'type', 'assignment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
