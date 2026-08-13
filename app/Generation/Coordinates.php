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

    /**
     * Count the hexes along the shortest path between this point's column and another's.
     *
     * **This is a different metric from everything else in this class, and mixing the two is the
     * mistake to avoid.** The rest of it compares squared distances through all three dimensions,
     * which is what the cluster generator's separation rule needs. This is the *map's* distance — how
     * many hexes apart two systems are drawn — and it ignores `z` entirely, exactly as `hexDistance()`
     * in `resources/js/lib/cluster-hex.ts` does. `HomeStelliumGenerator` can measure its separation in
     * either of the two, and stores on the run which one a game was generated under — they answer
     * different questions rather than being two scales of one, so neither is the "real" distance.
     *
     * This is the mirror of that TypeScript function and has to stay in step with it: the home stellia
     * are placed here and drawn there, so a drift would put a glowing hex where the rule says none
     * should be. `tests/Unit/CoordinatesTest.php` and `cluster-hex.test.ts` carry the same literal
     * table of pairs for that reason.
     *
     * **`abs()` on the parity term is load-bearing in both languages.** `-3 % 2` is `-1` in PHP as it
     * is in JavaScript, so the raw remainder shears the negative-`x` half of the map down a row:
     * every distance *within* one half stays correct while every distance *across* the centre comes
     * back wrong, which is exactly the failure a glance does not catch.
     *
     * `intdiv()` throughout, because this stays in exact integer arithmetic the way the squared
     * comparisons above do — the row term's numerator is always even, and so is the sum of the three
     * cube deltas.
     */
    public function hexDistanceTo(self $other): int
    {
        [$ax, $ay, $az] = $this->toCube();
        [$bx, $by, $bz] = $other->toCube();

        return intdiv(abs($ax - $bx) + abs($ay - $by) + abs($az - $bz), 2);
    }

    /**
     * Convert this point's column and row to cube coordinates, the form hex distance is measured in.
     *
     * The three axes sum to zero. Offset coordinates tessellate nicely but cannot be subtracted; cube
     * coordinates can, which is the whole reason for converting. `z` takes no part — a hex is one
     * `(x, y)` pair, the same unit `sharesColumnWith()` compares.
     *
     * @return array{int, int, int}
     */
    private function toCube(): array
    {
        $column = $this->x;
        $row = $this->y - intdiv($column - abs($column) % 2, 2);

        return [$column, -$column - $row, $row];
    }
}
