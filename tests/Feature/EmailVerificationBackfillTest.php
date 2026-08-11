<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The email verification backfill migration
|--------------------------------------------------------------------------
|
| The migration has already run by the time a test starts, against an empty users table,
| so it is re-run here by hand against rows that look like the pre-existing accounts it
| exists for. `require` returns the anonymous class the migration file returns.
|
*/

/**
 * Load the backfill migration.
 */
function backfillMigration(): Migration
{
    $migration = require database_path(
        'migrations/2026_08_11_151323_backfill_email_verified_at_for_existing_users.php'
    );

    expect($migration)->toBeInstanceOf(Migration::class);

    return $migration;
}

test('the backfill verifies accounts that predate the invitation-only flow', function () {
    $existing = User::factory()->unverified()->create();

    expect($existing->email_verified_at)->toBeNull();

    backfillMigration()->up();

    expect($existing->refresh()->email_verified_at)->not->toBeNull();
    expect($existing->hasVerifiedEmail())->toBeTrue();
});

test('the backfill leaves an already verified account untouched', function () {
    $verified = User::factory()->create();
    $originalVerifiedAt = $verified->email_verified_at?->toString();

    $this->travel(1)->hours();

    backfillMigration()->up();

    expect($verified->refresh()->email_verified_at?->toString())->toBe($originalVerifiedAt);
});

test('the backfill is a no-op on an empty users table', function () {
    expect(DB::table('users')->count())->toBe(0);

    backfillMigration()->up();

    expect(DB::table('users')->count())->toBe(0);
});

test('the backfill is deliberately irreversible and unverifies nobody', function () {
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    $migration = backfillMigration();
    $migration->up();
    $migration->down();

    expect($verified->refresh()->email_verified_at)->not->toBeNull();
    expect($unverified->refresh()->email_verified_at)->not->toBeNull();
});
