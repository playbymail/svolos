<?php

namespace App\Generation;

/**
 * One integer point in a game's cluster.
 *
 * Immutable, and deliberately not a model: the cluster generator works entirely in these before a row
 * exists, which is what lets it be tested without a database. `App\Models\Location` is where one of
 * these ends up.
 *
 * **Everything here compares squared distances.** The coordinates are integers, so squared distances
 * are integers too and comparisons are exact — no square root, no floating point, and "at least 2
 * apart" means exactly what it says at the boundary instead of depending on how `sqrt()` rounds.
 */
final readonly class Coordinates
{
    public function __construct(
        public int $x,
        public int $y,
        public int $z,
    ) {}

    /**
     * Determine whether this is the centre of the cluster, which never holds a location.
     */
    public function isOrigin(): bool
    {
        return $this->x === 0 && $this->y === 0 && $this->z === 0;
    }

    /**
     * Get the square of this point's distance from the centre.
     */
    public function squaredRadius(): int
    {
        return $this->x ** 2 + $this->y ** 2 + $this->z ** 2;
    }

    /**
     * Get this point's distance from the centre.
     */
    public function radius(): float
    {
        return sqrt($this->squaredRadius());
    }

    /**
     * Get the square of the distance between this point and another.
     */
    public function squaredDistanceTo(self $other): int
    {
        return ($this->x - $other->x) ** 2
            + ($this->y - $other->y) ** 2
            + ($this->z - $other->z) ** 2;
    }
}
