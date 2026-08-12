<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A stellium is the group of stars bound by gravity at one location — one to four of them. It is
     * one-to-one with a location today, and it is still its own table rather than a `star_count` column
     * on `locations`, because stars are rows with planets hanging off them and a stellium grows
     * attributes of its own in later stages. The unique key on `location_id` is what says "one per
     * location" out loud.
     *
     * **The table name is spelled out here and in the model.** Laravel's inflector pluralises
     * `Stellium` to `Stellia` — the same rule that turns `medium` into `media` — so a model left to
     * guess would look for a table that does not exist, and `foreignId('stellium_id')->constrained()`
     * would point a foreign key at `stellia` too. That is why `stars` names this table explicitly.
     *
     * `generation_run_id` cascades, so regenerating the stelliums drops the whole set at once while
     * the run rows keep the record of which seeds were tried.
     */
    public function up(): void
    {
        Schema::create('stelliums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stelliums');
    }
};
