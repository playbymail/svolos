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
     * Bring the schema onto the language the game is played in.
     *
     * `docs/reference/glossary.md` settles three words this table was written before: the countable
     * thing an entity is composed of and holds is a **unit**, the list it sits in is an
     * **inventory**, and the inventory holding what the entity was built from is **components**.
     * The table said asset, assignment and infrastructure for all three.
     *
     * ## Why this is a rename and not an edit of the create migration
     *
     * The create ran in production, so editing it would leave the deployed table named `assets`
     * forever while every fresh clone got `units` — the two would diverge silently and only a
     * `SQLSTATE[HY000]: no such table` on the server would say so. A rename migration is the same
     * amount of work and is true on both.
     *
     * The `infrastructure` → `components` update is data rather than schema: the value is what
     * `App\Enums\Inventory` is backed by, so rows written before this carry the old string and would
     * fail to hydrate into the enum. It runs after the column rename, and both are inside the same
     * migration because a database that has done one and not the other is broken either way round.
     */
    public function up(): void
    {
        Schema::rename('assets', 'units');

        Schema::table('units', function (Blueprint $table) {
            $table->renameColumn('assignment', 'inventory');
        });

        DB::table('units')->where('inventory', 'infrastructure')->update(['inventory' => 'components']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('units')->where('inventory', 'components')->update(['inventory' => 'infrastructure']);

        Schema::table('units', function (Blueprint $table) {
            $table->renameColumn('inventory', 'assignment');
        });

        Schema::rename('units', 'assets');
    }
};
