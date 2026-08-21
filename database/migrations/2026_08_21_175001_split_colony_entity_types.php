<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `colony` was one kind of entity; the glossary has always named three.
     *
     * Open air, enclosed and orbital colonies differ by where they sit and what they are sealed
     * against, and the difference became load-bearing the moment structural units were measured by
     * what they are assembled *for*: the same wall encloses `TL²` VU under an open sky, `TL² / 5`
     * sealed, and `TL² / 10` in orbit. A single `colony` case could not answer that.
     *
     * ## Every existing colony is an open air colony
     *
     * `App\Generation\StartingUnits` is the only thing that has ever written an entity, and the
     * colony it places is the one the advance expedition prepared: mines opened in the hills,
     * factories beside the rivers, fields cleared for farms. That is a settlement under its own sky.
     * An enclosed or orbital colony is something a player builds later, which is why `StartingUnits`
     * answers both with an empty kit rather than a guess.
     */
    public function up(): void
    {
        DB::table('entities')->where('type', 'colony')->update(['type' => 'open_air_colony']);
    }

    /**
     * Reverse the migrations.
     *
     * All three collapse back to the kind they came from. An enclosed or orbital colony built after
     * this migration ran has nowhere else to go, and losing the distinction is better than leaving a
     * row the old enum cannot hydrate.
     */
    public function down(): void
    {
        DB::table('entities')
            ->whereIn('type', ['open_air_colony', 'enclosed_colony', 'orbital_colony'])
            ->update(['type' => 'colony']);
    }
};
