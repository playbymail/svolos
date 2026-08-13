import type { ClusterLocation } from '@/types';

/**
 * The hex-map geometry for a game's cluster.
 *
 * The cluster is a hundred integer points inside a sphere of radius 15, and this file lays them on a
 * plane the way a strategic star map does: tessellate in regular hexagons, put each system in the hex
 * its `x, y` falls into, and carry `z` as a **number printed beside the system** rather than as a
 * projection. Counting hexes gives an apparent distance, and the real separation comes back out of it
 * with Pythagoras.
 *
 * Everything here is pure and integer where it can be — no DOM, no state — so the component stays
 * declarative and the arithmetic can be read on its own.
 */

/** The cluster's radius, mirroring `App\Generation\ClusterGenerator::RADIUS`. */
export const CLUSTER_RADIUS = 15;

/**
 * A hex in offset coordinates, which here **are** the location's own `x` and `y`.
 *
 * Flat-top hexes in odd-q offset: columns run left to right and every odd column is pushed down half a
 * row. Taking the column straight from `x` and the row straight from `y` means the grid *is* the
 * coordinate space — there is no binning step to get wrong, and a hex's label is the coordinate pair.
 */
export type HexCell = {
    x: number;
    y: number;
};

/**
 * The same hex in cube coordinates, which is the form distance is measured in.
 *
 * The three axes sum to zero. Offset coordinates tessellate nicely but cannot be subtracted; cube
 * coordinates can, which is the whole reason for converting.
 */
type Cube = {
    x: number;
    y: number;
    z: number;
};

/**
 * Convert an odd-q offset hex to cube coordinates.
 *
 * `Math.abs` on the parity term is load-bearing: half this cluster sits at a negative `x`, and
 * `-3 % 2` is `-1` in JavaScript rather than `1`, so the raw remainder would shear the left half of
 * the map by a row against the right half. Distances across the centre would come back wrong while
 * every distance within one half stayed right, which is the kind of bug that survives a casual look.
 */
export function toCube(cell: HexCell): Cube {
    const column = cell.x;
    const row = cell.y - (column - (Math.abs(column) % 2)) / 2;

    return { x: column, y: -column - row, z: row };
}

/**
 * Count the hexes along the shortest path between two cells, the map's apparent distance.
 *
 * This is what a hex map buys over a scatter plot: reach is something you can count off the picture
 * rather than compute. It ignores `z` entirely — see `trueDistance()` for the rest of it.
 */
export function hexDistance(from: HexCell, to: HexCell): number {
    const a = toCube(from);
    const b = toCube(to);

    return (
        (Math.abs(a.x - b.x) + Math.abs(a.y - b.y) + Math.abs(a.z - b.z)) / 2
    );
}

/**
 * The real separation of two systems: the hex count and the difference in height, squared together.
 *
 * The map flattens a sphere onto a plane, so two systems sharing a hex can still be thirty units
 * apart. This is the short-form Pythagorean that puts the third dimension back.
 */
export function trueDistance(
    from: ClusterLocation,
    to: ClusterLocation,
): number {
    const apparent = hexDistance(from, to);

    return Math.sqrt(apparent ** 2 + (from.z - to.z) ** 2);
}

/**
 * Where a hex's centre sits, in SVG user units, for a hex of the given size.
 *
 * `size` is the circumradius — centre to corner. Flat-top hexes step `1.5 · size` horizontally and
 * `√3 · size` vertically, and odd columns drop by half a row, which is what interlocks them.
 *
 * Those two steps are not equal, so the cluster's circular outline renders about 13% narrower than it
 * is tall. That is inherent to laying a round thing on a hex grid, and regular hexes are worth more
 * here than a perfectly round border.
 */
export function hexCentre(
    cell: HexCell,
    size: number,
): { cx: number; cy: number } {
    const offset = (Math.abs(cell.x) % 2) * 0.5;

    return {
        cx: size * 1.5 * cell.x,
        cy: size * Math.sqrt(3) * (cell.y + offset),
    };
}

/**
 * Build the SVG path commands for one hexagon outline, centred on a cell.
 *
 * The whole empty grid is drawn as a *single* `<path>` built from several hundred of these, because
 * seven hundred separate elements — each with its own listeners — is a real cost for something that
 * is only ever a backdrop. The systems on top are what the reader interacts with.
 */
export function hexPath(cell: HexCell, size: number): string {
    const { cx, cy } = hexCentre(cell, size);
    const corners: string[] = [];

    for (let corner = 0; corner < 6; corner++) {
        const angle = (Math.PI / 180) * 60 * corner;
        const x = cx + size * Math.cos(angle);
        const y = cy + size * Math.sin(angle);

        corners.push(
            `${corner === 0 ? 'M' : 'L'}${x.toFixed(3)},${y.toFixed(3)}`,
        );
    }

    return `${corners.join('')}Z`;
}

/**
 * Every hex of the cluster's footprint, which is the disc the sphere casts on the plane.
 *
 * A sphere of radius 15 projects to a circle of radius 15, so every location lands inside this set
 * and the empty hexes around them show the void the cluster sits in — the reason to draw a hex grid
 * at all rather than plot bare points.
 */
export function clusterCells(radius: number = CLUSTER_RADIUS): HexCell[] {
    const cells: HexCell[] = [];

    for (let x = -radius; x <= radius; x++) {
        for (let y = -radius; y <= radius; y++) {
            if (x ** 2 + y ** 2 > radius ** 2) {
                continue;
            }

            cells.push({ x, y });
        }
    }

    return cells;
}

/**
 * One occupied hex: the systems that share it, and where to draw them.
 *
 * The list is more than a formality. A location is unique on `(x, y, z)` rather than on `(x, y)`, so
 * two systems at the same column and row but different heights are ordinary, not a clash — measured
 * over simulated clusters, about seven systems a game land in a hex somebody else already holds, and
 * as many as four can stack in one. A map that assumed one system per hex would look right almost
 * everywhere and quietly hide those.
 */
export type HexSystem = {
    key: string;
    cell: HexCell;
    cx: number;
    cy: number;
    locations: ClusterLocation[];
};

/**
 * Group the cluster into occupied hexes, ordered so the busiest draw last.
 *
 * Painting the crowded hexes on top keeps a stacked marker from hiding under a single one when their
 * outlines overlap, and it puts the map's landmarks in front.
 */
export function groupByHex(
    locations: ClusterLocation[],
    size: number,
): HexSystem[] {
    const hexes = new Map<string, HexSystem>();

    for (const location of locations) {
        const key = `${location.x},${location.y}`;
        const existing = hexes.get(key);

        if (existing) {
            existing.locations.push(location);

            continue;
        }

        const cell = { x: location.x, y: location.y };

        hexes.set(key, {
            key,
            cell,
            ...hexCentre(cell, size),
            locations: [location],
        });
    }

    for (const hex of hexes.values()) {
        hex.locations.sort((a, b) => a.ordinal - b.ordinal);
    }

    return [...hexes.values()].sort(
        (a, b) => a.locations.length - b.locations.length,
    );
}

/**
 * The mark radius for a system, in SVG user units, from the number of stars in its stellium.
 *
 * Size is the primary channel for the star count and the colour ramp only reinforces it, so the four
 * steps have to be legible on their own.
 *
 * A location whose stelliums stage has not run has no count — `null` is "not decided", never zero.
 * Its size is **not** a step of the ramp and is deliberately not one of the four: the stage runs for
 * the whole cluster at once, so an undecided mark is never drawn beside a decided one and has nothing
 * to be relatively sized against. It only has to read as a place.
 */
export function markRadius(starCount: number | null, size: number): number {
    if (starCount === null) {
        return size * 0.3;
    }

    return size * (0.24 + 0.08 * Math.min(starCount, 4));
}
