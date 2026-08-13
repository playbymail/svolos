import { describe, expect, it } from 'vitest';
import {
    CLUSTER_RADIUS,
    clusterCells,
    groupByHex,
    hexCentre,
    hexDistance,
    hexPath,
    markRadius,
    toCube,
    trueDistance,
} from '@/lib/cluster-hex';
import type { HexCell } from '@/lib/cluster-hex';
import type { ClusterLocation } from '@/types';

/*
 * The hex map's arithmetic, which is the one part of this feature with a provably right answer.
 *
 * It is worth testing for the same reason `GeneratorPurityTest` reads the generator sources: the
 * failure mode is invisible. A parity bug in `toCube()` still draws a hundred systems in a hundred
 * plausible hexes and still reports a distance for every pair — the numbers are simply wrong, and
 * wrong in a way that looking at the screen cannot show. It matters more now that the map is going to
 * the player page, where the same module answers "can I reach that system" for somebody playing.
 *
 * Vitest is imported explicitly rather than run with globals, so ESLint needs no environment of its
 * own for these files.
 */

const SIZE = 10;

/** Build a location with only the fields the hex geometry reads. */
function locationAt(
    ordinal: number,
    x: number,
    y: number,
    z: number,
    starCount: number | null = 1,
): ClusterLocation {
    return {
        id: ordinal,
        ordinal,
        x,
        y,
        z,
        radius: Math.sqrt(x ** 2 + y ** 2 + z ** 2),
        star_count: starCount,
        planet_count: null,
        /* Nobody's home: the geometry here reads neither, and a fixture should not imply one. */
        home_seat_id: null,
        home_player_name: null,
    };
}

describe('toCube', () => {
    it('returns cube coordinates that sum to zero for every cell in the cluster', () => {
        /* The defining property of cube coordinates. A parity slip breaks it immediately. */
        for (const cell of clusterCells()) {
            const cube = toCube(cell);

            expect(cube.x + cube.y + cube.z).toBe(0);
        }
    });

    it('treats a negative odd column with the same parity as a positive one', () => {
        /*
         * `Math.abs` is what makes this true: `-1 % 2` is `-1` in JavaScript, not `1`, so the raw
         * remainder shifts negative odd columns a row against positive ones. The grid stays
         * self-consistent within each half, which is why nothing else notices.
         */
        expect(toCube({ x: 1, y: 0 }).z).toBe(0);
        expect(toCube({ x: -1, y: 0 }).z).toBe(1);
        expect(toCube({ x: -3, y: 0 }).z).toBe(2);
    });
});

describe('hexDistance', () => {
    it('is zero to itself and symmetric', () => {
        const a: HexCell = { x: -4, y: 7 };
        const b: HexCell = { x: 6, y: -2 };

        expect(hexDistance(a, a)).toBe(0);
        expect(hexDistance(a, b)).toBe(hexDistance(b, a));
    });

    it('calls a cell one hex from the origin that is drawn touching it', () => {
        /*
         * The regression that `Math.abs` in `toCube()` exists for, pinned as a single number. Drop the
         * `Math.abs` and this reads 2 — the hex is drawn against the origin and reported two steps
         * away. Every distance *within* one half of the map stays correct, so only a case that crosses
         * the parity boundary catches it.
         */
        expect(hexDistance({ x: -1, y: -1 }, { x: 0, y: 0 })).toBe(1);
    });

    it('agrees with the grid actually drawn, for cells on both sides of the centre', () => {
        /*
         * The general form of the test above, and the one that would catch a parity bug this file's
         * author had not thought of: `hexCentre()` decides where a hex is *painted* and `toCube()`
         * decides how far away it is *said* to be. If those two disagree about which columns are
         * offset, the map lies. So take the six cells physically touching each sample and require them
         * to be exactly the six the distance function calls adjacent.
         */
        const adjacent = Math.sqrt(3) * SIZE;
        const samples: HexCell[] = [
            { x: 0, y: 0 },
            { x: 1, y: 1 },
            { x: -1, y: -1 },
            { x: -3, y: 2 },
            { x: 4, y: -3 },
            { x: -6, y: -5 },
            { x: 7, y: 8 },
        ];

        for (const cell of samples) {
            const here = hexCentre(cell, SIZE);
            const touching: HexCell[] = [];
            const called: HexCell[] = [];

            for (let dx = -2; dx <= 2; dx++) {
                for (let dy = -2; dy <= 2; dy++) {
                    if (dx === 0 && dy === 0) {
                        continue;
                    }

                    const other = { x: cell.x + dx, y: cell.y + dy };
                    const there = hexCentre(other, SIZE);
                    const gap = Math.hypot(
                        there.cx - here.cx,
                        there.cy - here.cy,
                    );

                    if (Math.abs(gap - adjacent) < 0.0001) {
                        touching.push(other);
                    }

                    if (hexDistance(cell, other) === 1) {
                        called.push(other);
                    }
                }
            }

            /* A hexagon has six neighbours; anything else means the layout itself is wrong. */
            expect(touching).toHaveLength(6);
            expect(called.sort(byCell)).toEqual(touching.sort(byCell));
        }
    });

    it.each([
        [-1, -1, 0, 0, 1],
        [-7, -2, 3, 1, 10],
        [-3, 4, 3, -4, 11],
        [-1, 0, 1, 0, 2],
        [-5, -5, 5, 5, 15],
        [0, 0, 0, 7, 7],
        [-2, 3, -9, -3, 9],
        [7, -6, -8, 2, 15],
    ])(
        'matches the table pinned in CoordinatesTest.php: (%i, %i) to (%i, %i) is %i hexes',
        (ax, ay, bx, by, hexes) => {
            /*
             * **This table is duplicated verbatim in `tests/Unit/CoordinatesTest.php`, and the two
             * move together.** `App\Generation\Coordinates::hexDistanceTo()` is a second
             * implementation of this function, because the server places the home stellia against
             * this metric and the map draws them — and the properties above only prove each
             * implementation is internally sound, never that the two agree on a number. These pairs
             * all straddle the parity boundary, which is where they would disagree first.
             */
            expect(hexDistance({ x: ax, y: ay }, { x: bx, y: by })).toBe(hexes);
        },
    );
});

describe('trueDistance', () => {
    it('reduces to the height difference for two systems sharing a hex', () => {
        /* Same column and row, different height — the stacked case the map has to keep honest. */
        const lower = locationAt(1, 3, -4, -6);
        const upper = locationAt(2, 3, -4, 9);

        expect(hexDistance(lower, upper)).toBe(0);
        expect(trueDistance(lower, upper)).toBe(15);
    });

    it('squares the hex count against the height difference', () => {
        /*
         * A pair taken off a real cluster: #51, the lone quadruple, and #42. Ten hexes apart on the
         * plane and seventeen apart in height, so √(100 + 289).
         */
        const quadruple = locationAt(51, -7, -2, 12);
        const triple = locationAt(42, 3, 1, -5);

        expect(hexDistance(quadruple, triple)).toBe(10);
        expect(trueDistance(quadruple, triple)).toBeCloseTo(Math.sqrt(389), 10);
    });

    it('is never shorter than the hexes between the two systems', () => {
        const cells = clusterCells().slice(0, 60);

        for (const cell of cells) {
            const from = locationAt(1, 0, 0, 0);
            const to = locationAt(2, cell.x, cell.y, 7);

            expect(trueDistance(from, to)).toBeGreaterThanOrEqual(
                hexDistance(from, to),
            );
        }
    });
});

describe('clusterCells', () => {
    it('covers the disc the sphere casts on the plane, and nothing outside it', () => {
        const cells = clusterCells();

        for (const cell of cells) {
            expect(cell.x ** 2 + cell.y ** 2).toBeLessThanOrEqual(
                CLUSTER_RADIUS ** 2,
            );
        }

        /*
         * The centre is *drawn* even though it can never hold a system — the generator rejects the
         * origin, and the map marks that hex rather than leaving one indistinguishable blank among
         * seven hundred.
         */
        expect(cells).toContainEqual({ x: 0, y: 0 });
    });

    it('lists every cell once', () => {
        const cells = clusterCells();
        const keys = new Set(cells.map((cell) => `${cell.x},${cell.y}`));

        expect(keys.size).toBe(cells.length);
    });

    it('reaches the rim on both axes', () => {
        const cells = clusterCells();

        expect(cells).toContainEqual({ x: CLUSTER_RADIUS, y: 0 });
        expect(cells).toContainEqual({ x: -CLUSTER_RADIUS, y: 0 });
        expect(cells).toContainEqual({ x: 0, y: CLUSTER_RADIUS });
        expect(cells).toContainEqual({ x: 0, y: -CLUSTER_RADIUS });
    });
});

describe('hexCentre', () => {
    it('pushes odd columns down half a row and leaves even ones alone', () => {
        const step = Math.sqrt(3) * SIZE;

        expect(hexCentre({ x: 0, y: 0 }, SIZE).cy).toBeCloseTo(0, 10);
        expect(hexCentre({ x: 2, y: 0 }, SIZE).cy).toBeCloseTo(0, 10);
        expect(hexCentre({ x: 1, y: 0 }, SIZE).cy).toBeCloseTo(step / 2, 10);
        expect(hexCentre({ x: -1, y: 0 }, SIZE).cy).toBeCloseTo(step / 2, 10);
    });

    it('steps columns by one and a half hexes', () => {
        expect(hexCentre({ x: 1, y: 0 }, SIZE).cx).toBeCloseTo(1.5 * SIZE, 10);
        expect(hexCentre({ x: -4, y: 0 }, SIZE).cx).toBeCloseTo(-6 * SIZE, 10);
    });
});

describe('groupByHex', () => {
    it('collects systems that share a column and row into one hex', () => {
        /*
         * Not an edge case: a location is unique on `(x, y, z)`, so roughly seven systems a game land
         * in a hex somebody already holds and as many as four can stack. A map built on one system per
         * hex looks right on ninety-odd hexes and hides the rest.
         */
        const hexes = groupByHex(
            [
                locationAt(1, 2, 3, -4),
                locationAt(2, 2, 3, 11),
                locationAt(3, -5, 6, 0),
            ],
            SIZE,
        );

        expect(hexes).toHaveLength(2);

        const shared = hexes.find((hex) => hex.locations.length === 2);

        expect(shared?.locations.map((location) => location.ordinal)).toEqual([
            1, 2,
        ]);
    });

    it('orders occupants by ordinal and the busiest hexes last', () => {
        const hexes = groupByHex(
            [
                locationAt(9, 0, 1, 0),
                locationAt(4, 0, 1, 5),
                locationAt(7, 3, 3, 0),
            ],
            SIZE,
        );

        /* Painted last means painted on top, so a stack is never hidden under a single. */
        expect(hexes.at(-1)?.locations).toHaveLength(2);
        expect(hexes.at(-1)?.locations.map((l) => l.ordinal)).toEqual([4, 9]);
    });

    it('places a hex where hexCentre says it goes', () => {
        const [hex] = groupByHex([locationAt(1, -3, 4, 0)], SIZE);
        const expected = hexCentre({ x: -3, y: 4 }, SIZE);

        expect(hex.cx).toBeCloseTo(expected.cx, 10);
        expect(hex.cy).toBeCloseTo(expected.cy, 10);
    });
});

describe('markRadius', () => {
    it('grows with the star count', () => {
        const sizes = [1, 2, 3, 4].map((count) => markRadius(count, SIZE));

        expect(sizes).toEqual([...sizes].sort((a, b) => a - b));
        expect(new Set(sizes).size).toBe(4);
    });

    it('gives an undecided location a size that is not a step of the ramp', () => {
        /*
         * `null` is "the stelliums stage has not run", never zero — every location gets at least one
         * star. The stage runs for the whole cluster at once, so an undecided mark is never drawn
         * beside a decided one; what matters is that it does not *coincide* with a ramp step, which
         * would quietly imply a star count nobody has decided yet.
         */
        const undecided = markRadius(null, SIZE);
        const steps = [1, 2, 3, 4].map((count) => markRadius(count, SIZE));

        expect(undecided).toBeGreaterThan(0);
        expect(steps).not.toContain(undecided);
    });

    it('clamps above the largest stellium the generator can make', () => {
        expect(markRadius(9, SIZE)).toBe(markRadius(4, SIZE));
    });
});

describe('hexPath', () => {
    it('draws a closed hexagon of six corners', () => {
        const path = hexPath({ x: 0, y: 0 }, SIZE);

        expect(path.startsWith('M')).toBe(true);
        expect(path.endsWith('Z')).toBe(true);
        expect(path.match(/[ML]/g)).toHaveLength(6);
    });
});

/** Order cells so two lists of them can be compared. */
function byCell(a: HexCell, b: HexCell): number {
    return a.x - b.x || a.y - b.y;
}
