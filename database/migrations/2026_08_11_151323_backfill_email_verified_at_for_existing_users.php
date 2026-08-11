<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `User` implements `MustVerifyEmail`, so the `verified` middleware really does block an
     * account whose `email_verified_at` is null — including from `/admin`. Accounts that predate
     * the invitation-only flow were created without any verification step, so their null column
     * records "never asked", not "asked and refused"; leaving them null would lock a legitimate
     * account out of the area this migration's sibling gates. Every account created from here on
     * is verified at its source: invitation acceptance proves control of the mailbox the
     * invitation was sent to, and `app:create-admin` runs on the server console.
     *
     * Running this unconditionally is safe. It is a single `UPDATE ... WHERE email_verified_at IS
     * NULL`, so on an empty `users` table — a fresh install, `migrate:fresh`, every test run — it
     * matches no rows and does nothing, and it can never clear a timestamp that is already set.
     * It writes through the query builder rather than the model on purpose: a historical migration
     * must keep doing the same thing regardless of what later changes to `User` (casts, mutators,
     * global scopes, observers) would otherwise make it do.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: which rows were unverified before `up()` ran is not recorded
     * anywhere, so the only reversal available would be to unverify every account, which would
     * lock out users this migration was never about.
     */
    public function down(): void {}
};
