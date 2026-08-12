<?php

namespace App\Enums;

/**
 * Where one stage of one game's generation stands, derived from that game's runs.
 *
 * There is no `generation_stage` column anywhere. The runs already say all of this — whether an
 * earlier stage has been accepted, whether this one has a pending run, whether it has an accepted
 * one — and a stored copy could disagree with the rows it was supposed to summarise. See
 * `App\Models\Game::generationStateFor()`.
 *
 * `Ready` and `Review` are what the screen keys its controls off: only a stage in `Review` offers
 * accept and regenerate, and only a stage in `Ready` offers the first run. They are presentation in
 * the same sense the gamemaster roster's `can_retire` is — `GenerationController` decides the same
 * thing again on the server, and a hidden control is not a check.
 */
enum GenerationStageState: string
{
    case Locked = 'locked';
    case Ready = 'ready';
    case Review = 'review';
    case Accepted = 'accepted';

    /**
     * Get the human readable label for the state.
     */
    public function label(): string
    {
        return match ($this) {
            self::Locked => 'Waiting on the stage before it',
            self::Ready => 'Not generated yet',
            self::Review => 'Awaiting review',
            self::Accepted => 'Accepted',
        };
    }
}
