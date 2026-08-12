<?php

namespace App\Enums;

/**
 * What became of one run of a generator, derived from its timestamps rather than stored.
 *
 * The same shape as `InvitationStatus`, and for the same reason: `accepted_at` and `superseded_at`
 * already say everything, so a column would be a second source of truth to keep in step. See
 * `App\Models\GenerationRun::status()`.
 *
 * A **superseded** run is one the gamemaster regenerated past. Its row survives — it is the record
 * that says which seeds were tried before the accepted one, which is half of why runs are stored at
 * all — while the locations or stelliums it produced are gone, because only one set of them can be
 * the game's at a time.
 */
enum GenerationRunStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Superseded = 'superseded';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Superseded => 'Replaced',
        };
    }
}
