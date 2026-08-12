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
     * The seed is the number every random decision in a game is drawn from, so a game with a
     * recorded seed can be replayed exactly. It is therefore **not nullable**: a game without one
     * could still be played, and nothing about that run would be reproducible afterwards.
     *
     * `unsignedInteger` is the width of the engine's seed rather than a guess at a range. PHP's
     * Mt19937 — `Random\Engine\Mt19937`, and the `mt_srand()` behind it — takes a 32-bit unsigned
     * seed, so `[0, 4294967295]` is exactly the set of seeds that produce distinct sequences.
     * Anything wider would let two different numbers in this column mean the same game, which is
     * the one property a stored seed exists to have. `App\Models\Game::SEED_MIN` and `SEED_MAX`
     * repeat the bounds for validation; change one and you must change the other.
     *
     * Adding it takes three steps because a random value cannot be a column default: add the
     * column nullable, give every existing game a seed of its own, then close it to nulls. The
     * backfill loops rather than issuing one `UPDATE ... SET seed = <random>`, because a single
     * statement would evaluate `random_int()` once in PHP and hand every game the same seed.
     *
     * The bounds are written out here instead of read from `Game::SEED_MAX`, and the write goes
     * through the query builder rather than the model, for the reason the sibling backfill
     * migration gives: a historical migration has to keep doing the same thing regardless of what
     * later changes to the model would otherwise make it do. On an empty table — a fresh install,
     * `migrate:fresh`, every test run — the loop matches no rows and does nothing.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('seed')->nullable()->after('short_name');
        });

        foreach (DB::table('games')->whereNull('seed')->pluck('id') as $id) {
            DB::table('games')->where('id', $id)->update(['seed' => random_int(0, 4294967295)]);
        }

        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('seed')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('seed');
        });
    }
};
