<?php

namespace App\Enums;

/**
 * The state an invitation is in, derived from its timestamps rather than stored.
 *
 * There is deliberately no column for this: `accepted_at` and `expires_at` already say everything,
 * and a stored copy would be a second source of truth that goes stale the moment an invitation
 * expires without anybody touching the row. See `App\Models\Invitation::status()`.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
        };
    }
}
