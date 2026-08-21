<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A unit is built at a technology level, and the level is part of what a row *is*.
     *
     * A ship built with `LSTR-10` can carry crated `LSTR-2` and run `LSTR-8` at the same time. Those
     * are three rows of one kind, so `technology_level` joins `(entity_id, type, inventory)` in the
     * unique key rather than hanging off it — leave it out and the second of them cannot be written
     * at all.
     *
     * ## Zero, not null
     *
     * Most kinds have a level; the raw commodities do not, and a report shows those as `FOOD` rather
     * than `FOOD-0`. The absent case is stored as `0` because **SQLite treats `NULL`s as distinct in
     * a unique index** — a nullable column would happily take two `(entity, food, cargo, NULL)` rows
     * and quietly break the one guarantee this table makes, and it would break it precisely for the
     * bulk commodities where a duplicate row does the most damage. `UnitType::hasTechnologyLevel()`
     * says which kinds are which, and `App\Generation\UnitHolding` refuses any other pairing.
     *
     * The column keeps its default of `0` for the same reason: for a kind with no level that is the
     * correct value rather than a placeholder, and for a kind with one the holding's constructor
     * rejects it before a row is built.
     *
     * ## The old index is dropped by name, because renaming a table does not rename its indexes
     *
     * `Schema::rename('assets', 'units')` and the `assignment` → `inventory` `renameColumn` both left
     * the unique index called `assets_entity_id_type_assignment_unique`, naming a table and a column
     * that no longer exist. SQLite carries an index's name through both operations unchanged, so
     * `dropUnique(['entity_id', 'type', 'inventory'])` — which derives the *conventional* name —
     * looks for `units_entity_id_type_inventory_unique` and finds nothing.
     *
     * The stale name is deterministic on every database, since every one of them reached this point
     * through the same two migrations, so dropping it by name is safe. The replacement is created
     * under the conventional name, which puts the schema back in step with what Laravel would derive.
     *
     * ## Why the backfill is one line
     *
     * Only `App\Generation\StartingUnits` has written a `units` row, and the only kind in the kits
     * that has a technology level is `light_structural` — the colony's buildings and the ship's hull,
     * both of the era that crossed the stars, so both are 10. Every other existing row is a kind with
     * no level, which `0` already says.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedTinyInteger('technology_level')->default(0)->after('inventory');
        });

        DB::table('units')->where('type', 'light_structural')->update(['technology_level' => 10]);

        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique('assets_entity_id_type_assignment_unique');
            $table->unique(['entity_id', 'type', 'inventory', 'technology_level']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The narrower key goes back first: rows that differ only by level would collide under it, so a
     * rollback with mixed levels in one inventory fails loudly here rather than silently dropping
     * one of them. It is restored under its **stale** name, so that rolling back and migrating
     * forward again lands on the same schema this migration expects to find.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'type', 'inventory', 'technology_level']);
            $table->unique(['entity_id', 'type', 'inventory'], 'assets_entity_id_type_assignment_unique');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('technology_level');
        });
    }
};
