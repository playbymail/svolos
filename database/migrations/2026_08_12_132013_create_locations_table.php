<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A location is one place in a game's cluster: an integer point inside a sphere of radius 15, with
     * the origin left empty. Coordinates are `tinyInteger` because −15…15 fits with room to spare, and
     * because storing them as anything wider would invite a later change to put a location where the
     * generator cannot reach.
     *
     * `game_id` is here as well as on the run this location came from, and that redundancy is
     * load-bearing: the two unique keys are what the *game* guarantees — no two locations share a
     * point, no two share an ordinal — and neither can be expressed through the run, because a
     * regenerated cluster is a different run with the same guarantees.
     *
     * `ordinal` is placement order within the run, so the same seed always numbers the cluster the
     * same way and the screen can name a location without inventing an identifier of its own.
     *
     * Both foreign keys cascade. Deleting a game takes its cluster with it, and so does discarding the
     * run that produced it — which is exactly what regenerating does: the attempt survives as a row in
     * `generation_runs`, its output does not.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->tinyInteger('x');
            $table->tinyInteger('y');
            $table->tinyInteger('z');
            $table->timestamps();

            $table->unique(['game_id', 'ordinal']);
            $table->unique(['game_id', 'x', 'y', 'z']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
