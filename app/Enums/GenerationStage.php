<?php

namespace App\Enums;

/**
 * The stages a game's world is generated in, in the order they must happen.
 *
 * **Declaration order is the dependency order.** A stage cannot be run until every stage before it
 * has been accepted, and `previous()` and `position()` both read `cases()` rather than a hand-kept
 * number, so inserting a stage in the middle reorders the workflow correctly by itself.
 *
 * Adding a case has one deliberate consequence worth knowing before you add one: a game is only
 * ready to leave setup when *every* case has an accepted run, so a new stage immediately makes every
 * unfinished game incomplete again. That is the intended behaviour — a game missing a generation
 * step is not ready to be played — and it is why the check sweeps `cases()` instead of naming the
 * last stage.
 */
enum GenerationStage: string
{
    case Cluster = 'cluster';
    case Stelliums = 'stelliums';
    case Planets = 'planets';

    /**
     * Get the human readable label for the stage.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cluster => 'Cluster',
            self::Stelliums => 'Stelliums',
            self::Planets => 'Planets',
        };
    }

    /**
     * Get the sentence the screen uses to say what this stage produces.
     */
    public function description(): string
    {
        return match ($this) {
            self::Cluster => 'Scatters the locations that make up the cluster, each one a place a game can happen.',
            self::Stelliums => 'Puts a stellium — one to four stars bound by gravity — at every location.',
            self::Planets => 'Gives every star one to ten planets, ordered outward, each with its habitability and its deposits.',
        };
    }

    /**
     * Get this stage's place in the order, counting from zero.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * Get the stage that has to be accepted before this one may run.
     */
    public function previous(): ?self
    {
        return self::cases()[$this->position() - 1] ?? null;
    }
}
