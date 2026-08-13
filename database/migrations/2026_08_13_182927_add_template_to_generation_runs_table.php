<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The home system every player begins in, settled once and stored on the run that settled it.
 *
 * **A column rather than a table, because a template is an input.** Everything this schema gives a
 * table of its own — locations, stelliums, stars, planets, home stelliums — is a *generated
 * artefact*, written by a stage and thrown away by its `discard()`. What a stage was *asked* for
 * lives on the run beside the seed: `traveler`, `minimum_separation`, `separation_in_hexes`, and now
 * this. So a superseded template run keeps its template exactly as a superseded cluster run keeps
 * its seed, and `GenerateHomeTemplate` writes no rows at all.
 *
 * Nullable because it is only ever set on a `home_stellia_template` run — the same way `traveler`
 * means nothing outside a cluster run — and because it is written on the second save inside
 * `RunGeneration`, once the stage has either parsed the uploaded document or drawn one from the
 * seed. A generated template stores `file` as null, which is how the screen tells the two apart.
 *
 * `seed` is deliberately **not** touched. A template run is seeded like every other one: with the
 * box ticked the seed is what the template is drawn from, and with a document uploaded it is still
 * the number the run is recorded under.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->json('template')->nullable()->after('separation_in_hexes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
