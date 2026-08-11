<?php

namespace App\Enums;

/**
 * Where a game is in its life, from being set up to being put away.
 *
 * The order of the cases is the order a game normally travels through them, which is why the enum is
 * also what the administration screen's picker lists: `Setup` while the roster is being assembled,
 * `Active` once turns are being taken, `Paused` for a game that has stopped without ending,
 * `Completed` for one that ended, and `Archived` for one that should no longer appear in the ordinary
 * run of things. Nothing enforces that order — an administrator can move a game to any status — but a
 * status is never derived, unlike `App\Enums\InvitationStatus`: a paused game and an active one differ
 * by a decision somebody made, not by a timestamp.
 *
 * `Archived` is the only case with behaviour attached to it: `Game::unarchived()` excludes it, so an
 * archived game stays addressable but drops out of the lists that assume a game is still in play.
 */
enum GameStatus: string
{
    case Setup = 'setup';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Setup => 'Setup',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }
}
