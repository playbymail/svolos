<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `Structural` is the **category**; the two kinds in it are `Structure` and `Light Structure`.
     *
     * The category table settled on 2026-08-21 named thirteen categories, one of which is
     * `Structural` — "material assembled to enclose volume for ships and colonies" — and gave its
     * item codes as `STRC` and `STRL`. The kinds had been carrying the category's name and the
     * codes `STRU` and `LSTR`, so both moved: `structural` → `structure`, `light_structural` →
     * `light_structure`.
     *
     * A category and a kind sharing a word is the confusion this removes. It is the same argument
     * that took `Infrastructure` off `Inventory` a day earlier — and just as well, since
     * `Infrastructure` is now a *category* too.
     *
     * Values rather than labels, because `App\Enums\UnitType` is backed by these strings: a row
     * still saying `light_structural` throws on hydration rather than reading as anything.
     */
    public function up(): void
    {
        DB::table('units')->where('type', 'structural')->update(['type' => 'structure']);
        DB::table('units')->where('type', 'light_structural')->update(['type' => 'light_structure']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('units')->where('type', 'structure')->update(['type' => 'structural']);
        DB::table('units')->where('type', 'light_structure')->update(['type' => 'light_structural']);
    }
};
