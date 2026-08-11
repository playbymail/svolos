<?php

namespace App\Enums;

/**
 * The role a user holds **inside one game**, carried by their seat at it.
 *
 * ## This carries no application permissions
 *
 * A `Gamemaster` seat does not make anyone an administrator of anything. It grants nothing outside
 * the one game the seat belongs to: not access to `/admin`, not the ability to see or edit another
 * game, not the ability to touch another account. Conversely, an administrator is not automatically a
 * gamemaster of any game — holding `App\Enums\UserRole::Admin` says nothing about seats.
 *
 * The two enums are deliberately unrelated systems and must never be unified: no shared `roles`
 * table, no common `Role` enum or `HasRoles` trait, and no authorisation check that reads both.
 * `App\Http\Middleware\EnsureUserIsAdmin` must never consult a game or a seat, and nothing that
 * authorises a game may consult `users.role`.
 *
 * The reason is the difference in blast radius. A game role is meant to be cheap to grant — handed
 * out by whoever runs a game, to whoever they invite — while an application role reaches every
 * account in the installation. Anything that lets the first imply the second turns "let me run a
 * game" into a privilege escalation path. See `.ai/rules/roles.md` and `.ai/rules/games.md`.
 */
enum GameRole: string
{
    case Player = 'player';
    case Gamemaster = 'gamemaster';

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Player => 'Player',
            self::Gamemaster => 'Gamemaster',
        };
    }
}
