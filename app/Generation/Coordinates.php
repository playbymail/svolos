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
     * Determine whether this point stands anywhere in the cluster's middle column.
     *
     * Wider than `isOrigin()` by the whole height of the cluster: `(0, 0, -10)` is thirty units from
     * the centre and is not the origin, but it shares the origin's `(x, y)` — so the hex map draws it
     * in the middle hex, the one the map presents as the empty centre of the cluster. Only the
     * traveler constraint refuses it; an ordinary cluster puts a system there about one game in six.
     */
    public function isInCentreColumn(): bool
    {
        return $this->x === 0 && $this->y === 0;
    }

    /**
     * Determine whether this point stands directly above or below another.
     *
     * A "column" is one `(x, y)` pair with the height left out of it, which is the unit the hex map
     * draws: `ClusterHexMap` puts a system in the hex its `x, y` falls into and prints `z` beside it,
     * so two points sharing a column share a hex however far apart they are vertically. Ordinarily
     * that is fine — locations are unique on `(x, y, z)`, not on `(x, y)` — and it is only the
     * traveler constraint in `ClusterGenerator` that forbids it.
     */
    public function sharesColumnWith(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y;
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
