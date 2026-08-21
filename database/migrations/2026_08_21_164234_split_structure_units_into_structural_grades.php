<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `structure` was one kind; it is a **category** holding two.
     *
     * The catalogue now carries `structural` (STRU) and `light_structural` (LSTR), so no row may
     * keep saying `structure` — `App\Enums\UnitType` is backed by these strings and a stale one
     * throws on hydration rather than reading as anything.
     *
     * ## Why every existing row becomes light structural
     *
     * Only `App\Generation\StartingUnits` has ever written a `structure` row, in exactly two places:
     * the colony's buildings and the ship's hull. Both are light structural now, so the update needs
     * no discrimination — there is no third caller whose rows would be guessed at. If that stops
     * being true before this migration runs somewhere, it stops being a safe blanket update.
     *
     * The measures moved as well and this migration says nothing about them, because it does not
     * have to: mass and volume are read from the enum by kind and quantity, and are stored nowhere.
     */
    public function up(): void
    {
        DB::table('units')->where('type', 'structure')->update(['type' => 'light_structural']);
    }

    /**
     * Reverse the migrations.
     *
     * Both grades collapse back to the single kind they came from. `structural` is included for
     * completeness: nothing writes it today, but a rollback that left rows the old enum cannot
     * hydrate would be worse than one that over-collects.
     */
    public function down(): void
    {
        DB::table('units')->whereIn('type', ['structural', 'light_structural'])->update(['type' => 'structure']);
    }
};
