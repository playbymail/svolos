<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kit every player in a game begins with, stored on the run that settled it.
 *
 * **A column rather than a table, because a kit is an input** — the same argument the migration
 * adding `generation_runs.template` makes, and for the same reason. What a stage was *asked* for
 * lives on the run beside the seed: `traveler`, `minimum_separation`, `separation_in_hexes`,
 * `template`, and now this. Everything with a table of its own — locations, stelliums, stars,
 * planets, home stelliums, entities — is a generated artefact thrown away by a stage's `discard()`.
 *
 * So a superseded units run keeps the kit it was asked to use exactly as a superseded cluster run
 * keeps its seed, and the screen can still name the document a gamemaster tried and rejected.
 *
 * **There is deliberately no foreign key to `kit_templates`.** Choosing a saved kit copies its
 * document onto the run. A run has to stay a record of what it was actually given, and a gamemaster
 * tidying their library months later must not be able to reach into a game that has been played
 * since — which a key, nullable or not, would either allow or forbid, both wrongly.
 *
 * Nullable because it is only ever set on an `assets` run — the way `traveler` means nothing outside
 * a cluster run — and because it is written on the second save inside `RunGeneration`, once the
 * stage has either parsed the document it was handed or drawn one from the seed. A generated kit
 * stores `file` as null and its `seed` as the number it came from.
 *
 * `seed` is deliberately **not** touched. A units run is seeded like every other one: with nothing
 * uploaded the seed is what the kit is drawn from, and with a document chosen it is still the number
 * the run is recorded under.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->json('kit')->nullable()->after('template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table) {
            $table->dropColumn('kit');
        });
    }
};
