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
     * Everything a player configures about themselves at one game. It goes on the seat rather than
     * into a table of its own because `game_seats` already *is* the account-at-a-game row — a second
     * table joined on the same key would be the same row with a join in front of it.
     *
     * ## `number` is the empire number, and it is stored rather than counted
     *
     * A player is empire 3 at their game, and stays empire 3. The obvious alternative — the seat's
     * position among the game's active player seats — is wrong in a way that only shows up later:
     * retiring empire 2 would renumber everybody after them, and the engine history that already
     * named them would start pointing at somebody else. Seats are retired rather than deleted for
     * exactly that reason (see `App\Models\GameSeat`), so the number they were given has to outlive
     * their time in the game too.
     *
     * It follows that the sequence **counts retired seats and never reuses a number**, and that it
     * is per game rather than global: `unique(game_id, number)` says both. `GameSeat::booted()`
     * assigns it at creation, the way `Game::booted()` assigns a seed, so every path that makes a
     * seat gets one — the two seat controllers, the factory, anything later.
     *
     * Adding it takes three steps for the reason `..._add_seed_to_games_table.php` needed three: a
     * per-row value cannot be a column default. Add it nullable, walk the existing seats oldest
     * first handing out numbers per game, then close it to nulls and add the index. The walk orders
     * by `id` because that is the order the seats were created in, which is the order they would
     * have been numbered in had the column existed all along. On an empty table — a fresh install,
     * `migrate:fresh`, every test run — it matches no rows and does nothing.
     *
     * ## `empire_name` is nullable, and null is the point
     *
     * The name shown for an unnamed empire is "Game ACME Seat 3", built by
     * `GameSeat::empireName()` at read time. Writing that string into the column at creation would
     * be a copy that goes stale the moment an administrator renames the game, and it would throw
     * away the one fact worth keeping: null is how the application knows this player has not chosen
     * a name yet.
     *
     * The width matches `GameSeat::EMPIRE_NAME_MAX_LENGTH`, which is a limit on the name's purpose
     * rather than on the column's capacity — it is read in lists beside other empires. Change one
     * and you must change the other.
     *
     * ## `email_notifications` defaults to false
     *
     * Nobody is mailed until they ask to be. `App\Actions\Games\AnnounceGameActivation` reads this
     * column and nothing else, so a default of true would mail every player of every existing game
     * the next time one was activated.
     */
    public function up(): void
    {
        Schema::table('game_seats', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('user_id');
            $table->string('empire_name', 60)->nullable()->after('number');
            $table->boolean('email_notifications')->default(false)->after('empire_name');
        });

        $assigned = [];

        foreach (DB::table('game_seats')->orderBy('id')->get(['id', 'game_id']) as $seat) {
            $assigned[$seat->game_id] = ($assigned[$seat->game_id] ?? 0) + 1;

            DB::table('game_seats')
                ->where('id', $seat->id)
                ->update(['number' => $assigned[$seat->game_id]]);
        }

        Schema::table('game_seats', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable(false)->change();
            $table->unique(['game_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The index is dropped by name before its columns are, because dropping a column out from under
     * an index it is part of is not something every driver will do quietly.
     */
    public function down(): void
    {
        Schema::table('game_seats', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'number']);
            $table->dropColumn(['number', 'empire_name', 'email_notifications']);
        });
    }
};
