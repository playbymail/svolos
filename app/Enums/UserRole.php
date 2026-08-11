<?php

namespace App\Enums;

/**
 * An application-wide role held by a user account.
 *
 * `Admin` is the only role that grants access to the `/admin` area; `Member` is the default every
 * account starts on. This is deliberately *not* a general role system: it is a single indexed
 * column on `users` with exactly two values, and it has nothing to do with the per-game roles a
 * user may hold. See `.ai/rules/roles.md`.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Member => 'Member',
        };
    }
}
