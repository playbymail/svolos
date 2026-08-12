<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per star, ordered within its stellium. There are no attributes yet — mass, class,
     * luminosity and the rest arrive with the planets stage that hangs off these rows — but the stars
     * are created now rather than being left as a count, because a count cannot own a planet.
     *
     * `constrained('stelliums')` names the table on purpose: inference would derive it from the column
     * as `stellia`, since Laravel's inflector treats `stellium` like `medium`. Leave the argument in.
     *
     * The unique key on `(stellium_id, ordinal)` keeps the numbering honest, and the cascade means a
     * regenerated stellium takes its stars with it.
     */
    public function up(): void
    {
        Schema::create('stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stellium_id')->constrained('stelliums')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordinal');
            $table->timestamps();

            $table->unique(['stellium_id', 'ordinal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stars');
    }
};
