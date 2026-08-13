<?php

namespace App\Actions\Agents;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates an agent account. The only place `users.is_agent` is ever set.
 *
 * An agent is an ordinary account in every way the game cares about: it takes seats, holds a game
 * role, and will have its orders attributed to a seat like anybody else. Three things differ, and all
 * three are decided here:
 *
 * - **It gets an unusable password.** The column is not nullable, so it gets 64 random characters
 *   nobody has ever seen. That is housekeeping rather than a control — the control is
 *   `User::isAgent()` and the four refusals that read it — but it means the account is not reachable
 *   by guessing even if one of those refusals is ever lost.
 * - **Its address is on `.invalid`.** RFC 2606 reserves that domain as non-routable, so an address
 *   minted here can never collide with a mailbox somebody owns, which is the same reasoning
 *   `Database\Seeders\DevelopmentUserSeeder` gives for `example.com`.
 * - **Its email is marked verified.** There is no mailbox to confirm and nobody to send a link to, so
 *   leaving it unverified would strand the account behind `verified` for no benefit.
 *   `app:create-admin` marks a newly created administrator the same way and for the same reason.
 */
class CreateAgent
{
    /**
     * The domain every generated agent address uses.
     */
    public const string DOMAIN = 'agents.invalid';

    /**
     * Create an agent account.
     *
     * `is_agent` is assigned explicitly rather than mass-assigned, for the reason `role` is: it is a
     * privilege boundary, and everything else that writes an account writes from request input. It is
     * absent from `User`'s `#[Fillable]` list and must stay absent.
     */
    public function handle(string $name, ?string $email = null): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email ?? $this->generateEmailAddress($name);
        $user->password = Str::random(64);
        $user->is_agent = true;
        $user->save();

        $user->markEmailAsVerified();

        return $user;
    }

    /**
     * Derive an unused address on the reserved domain from the agent's name.
     *
     * A suffix is appended only on collision, so the common case reads as the name the administrator
     * typed. The loop terminates because each pass appends a fresh random suffix to a growing pool of
     * taken addresses; in practice it runs once.
     */
    public function generateEmailAddress(string $name): string
    {
        $slug = Str::slug($name) ?: 'agent';

        $address = $slug.'@'.self::DOMAIN;

        while (User::query()->where('email', $address)->exists()) {
            $address = $slug.'-'.Str::lower(Str::random(6)).'@'.self::DOMAIN;
        }

        return $address;
    }
}
