<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Six known member accounts, so a human can sign in locally and click around.
 *
 * The credentials are deliberately guessable and are published in this file, which makes two rules
 * non-negotiable:
 *
 * - **The seeder returns early outside `local` and `testing`.** A known email address with a known
 *   password is an unauthenticated administrator's foothold away from being a real account, so
 *   `php artisan db:seed` on a deployed installation must create nothing at all. The guard lives
 *   here rather than in `DatabaseSeeder` so it cannot be lost by re-wiring the manifest, and so
 *   `php artisan db:seed --class=DevelopmentUserSeeder` is guarded too.
 * - **Accounts that already exist are skipped, never updated.** Local databases get used: one of
 *   the six gets renamed, promoted to administrator, given two-factor, seated in a game. Re-running
 *   the seeder must not undo any of that, so an address that is already taken is left exactly as it
 *   is and only the missing ones are created. The address is the identity here — that is what the
 *   helpers below promise — so renaming or promoting an account keeps it, while changing its email
 *   frees the address for the next run to fill in again.
 *
 * Every account is a plain verified member. Nothing here sets `role`: promoting an account is
 * `app:create-admin` or the accounts screen, and both are worth exercising by hand.
 */
class DevelopmentUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * How many development accounts exist. `email()` and `password()` accept `1` up to this value.
     */
    public const int ACCOUNTS = 6;

    /**
     * The domain every seeded address uses.
     *
     * RFC 2606 reserves `example.com` for documentation and testing, so it resolves nowhere and no
     * address minted here can reach a mailbox somebody else owns.
     */
    private const string DOMAIN = 'example.com';

    /**
     * Get the email address of the nth development account.
     *
     * Tests and documentation call this instead of writing the address out, so the scheme lives in
     * exactly one place and a test cannot quietly keep passing against an account that moved.
     */
    public static function email(int $index): string
    {
        return 'user'.self::assertSeededIndex($index).'@'.self::DOMAIN;
    }

    /**
     * Get the password of the nth development account.
     */
    public static function password(int $index): string
    {
        return 'password'.self::assertSeededIndex($index);
    }

    /**
     * Seed the development member accounts.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (range(1, self::ACCOUNTS) as $index) {
            $email = self::email($index);

            if (User::query()->where('email', $email)->exists()) {
                continue;
            }

            /*
             * Built through the factory so the verified timestamp, the role default and every other
             * column stay defined in one place. `password` is handed over in plain text on purpose:
             * the `hashed` cast on `User` hashes it on the way in.
             */
            User::factory()->create([
                'name' => "Member {$index}",
                'email' => $email,
                'password' => self::password($index),
            ]);
        }
    }

    /**
     * Return the index, or reject one that no seeded account corresponds to.
     *
     * Silently returning `user7@example.com` would hand a test a set of credentials that never gets
     * created, and the failure would surface as an unexplained failed sign-in rather than as the
     * off-by-one it is.
     */
    private static function assertSeededIndex(int $index): int
    {
        if ($index < 1 || $index > self::ACCOUNTS) {
            throw new InvalidArgumentException(
                "There is no development account [{$index}]: this seeder maintains 1 to ".self::ACCOUNTS.'.'
            );
        }

        return $index;
    }
}
