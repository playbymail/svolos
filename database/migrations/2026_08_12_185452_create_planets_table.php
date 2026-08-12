<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per planet, ordered outward from its star. `ordinal` is the orbit — planet 1 is the
     * innermost — and it is the whole of a planet's identity, which is why there is no name column:
     * a planet is addressed as its star plus its position.
     *
     * Two absences are deliberate:
     *
     * - **No `game_id`.** `locations` carries one because its unique keys — `(game_id, ordinal)` and
     *   `(game_id, x, y, z)` — are guarantees about the *game*. A planet's only uniqueness is within
     *   its star, so the column would buy nothing and be a pure duplicate of a path already walkable
     *   through `stars` → `stelliums` → `locations`.
     * - **No `zone` column.** Which zone a planet sits in is a function of `ordinal` and how many
     *   planets its star has, both already here, so a column could only ever disagree with them. It
     *   is derived where it is needed, the way `Location::radius()` is.
     *
     * `unsignedTinyInteger` covers every value the generator can produce: habitability is capped at 25
     * by its dice, and the largest deposit any table reaches is 35 (an asteroid field's metals or
     * minerals). `PlanetGeneratorTest` asserts those bounds against the tables, so a later change that
     * outgrows a byte fails there rather than by silent truncation.
     *
     * Both foreign keys cascade: regenerating the planets drops the whole set through
     * `generation_run_id`, and re-running an earlier stage drops them through `star_id` — the chain is
     * four deep now, runs → locations → stelliums → stars → planets.
     */
    public function up(): void
    {
        Schema::create('planets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('star_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('ordinal');
            $table->string('type');
            $table->unsignedTinyInteger('habitability');
            $table->unsignedTinyInteger('fuel');
            $table->unsignedTinyInteger('metals');
            $table->unsignedTinyInteger('minerals');
            $table->timestamps();

            $table->unique(['star_id', 'ordinal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planets');
    }
};
