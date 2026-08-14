<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| SQLite concurrency settings
|--------------------------------------------------------------------------
|
| The default rollback journal takes an exclusive lock for the whole of a write, so two
| connections writing at once means one of them gets
| `SQLSTATE[HY000]: General error: 5 database is locked` — an immediate 500 rather than a
| wait. A concurrent burst against api/* produced exactly that, four times, and every
| signed-in request writes the sessions table on the same file.
|
| WAL plus a busy timeout is the fix, and the two are a pair: WAL alone still fails on the
| instant two writers collide, and a busy timeout alone would make every reader queue behind
| a writer.
|
| These are asserted against a **real file database** rather than against the config array.
| The values are applied by Laravel's SQLite connector as pragmas when a connection opens,
| and it is that — not the presence of a config key — that decides whether the lock errors
| come back. The test suite itself runs on `:memory:`, which has no journal file and reports
| `memory` whatever is asked for, so asking the test connection would prove nothing.
|
*/

test('a file database opens in WAL mode with a busy timeout', function () {
    $path = storage_path('framework/testing/wal-check-'.bin2hex(random_bytes(6)).'.sqlite');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '');

    config([
        'database.connections.wal_check' => [
            ...config('database.connections.sqlite'),
            'database' => $path,
        ],
    ]);

    try {
        $connection = DB::connection('wal_check');

        /*
         * `PRAGMA journal_mode` returns the mode as a value, so this reads back what the connector
         * actually negotiated with SQLite rather than what was requested.
         */
        expect($connection->scalar('pragma journal_mode'))->toBe('wal')
            ->and($connection->scalar('pragma busy_timeout'))->toBe(5000);

        $connection->disconnect();
    } finally {
        /* WAL leaves `-wal` and `-shm` beside the database; a test that forgets them leaves litter. */
        foreach (['', '-wal', '-shm'] as $suffix) {
            File::delete($path.$suffix);
        }
    }
});

test('the sqlite connection keeps SQLite own durability default', function () {
    /*
     * `synchronous` stays null on purpose, so SQLite's own default (`FULL`, an fsync per commit)
     * applies. `NORMAL` is the usual companion to WAL and is faster, but it trades durability across
     * an OS crash for that speed — a decision worth making deliberately rather than inheriting from
     * a performance tweak. Pinned so the trade cannot be made by accident.
     */
    expect(config('database.connections.sqlite.synchronous'))->toBeNull();
});
