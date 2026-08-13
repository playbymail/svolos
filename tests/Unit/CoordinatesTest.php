<?php

use App\Generation\ClusterGenerator;
use App\Generation\Coordinates;

/*
|--------------------------------------------------------------------------
| The cluster's geometry
|--------------------------------------------------------------------------
|
| `Coordinates` carries two different distances and the whole point of this file is that they are not
| the same question. `squaredDistanceTo()` measures through all three dimensions and is what the
| cluster generator's separation rule compares; `hexDistanceTo()` counts hexes on the plane the map
| draws and ignores height entirely, which is what `HomeStelliumGenerator` places against.
|
| The hex metric is duplicated in `resources/js/lib/cluster-hex.ts`, because the server places the
| home stellia and the client draws them. Two implementations of one function is a drift risk, so this
| file pins it two ways: a property strong enough to determine the metric uniquely, and a literal
| table repeated verbatim in `cluster-hex.test.ts`. The property is what catches a parity bug nobody
| thought of; the literals are what make a drift *noticed*.
|
*/

test('a point knows whether it is the centre, and knows the difference between the point and the column', function () {
    expect((new Coordinates(0, 0, 0))->isOrigin())->toBeTrue();
    expect((new Coordinates(0, 0, -10))->isOrigin())->toBeFalse();

    /*
     * The wider test, and the reason it exists: `(0, 0, -10)` is thirty units from the middle of the
     * cluster and is not the origin, but the map draws it in the middle hex.
     */
    expect((new Coordinates(0, 0, -10))->isInCentreColumn())->toBeTrue();
});

test('hex distance is zero to itself, symmetric, and zero exactly when two points share a column', function () {
    $a = new Coordinates(-4, 7, 3);
    $b = new Coordinates(6, -2, -8);
    /* Same column as $a, thirty units below it: the map draws these two in one hex. */
    $above = new Coordinates(-4, 7, -12);

    expect($a->hexDistanceTo($a))->toBe(0);
    expect($a->hexDistanceTo($b))->toBe($b->hexDistanceTo($a));

    expect($a->hexDistanceTo($above))->toBe(0);
    expect($a->sharesColumnWith($above))->toBeTrue();
    expect($a->hexDistanceTo($b) === 0)->toBe($a->sharesColumnWith($b));
});

test('every cell has exactly six neighbours, on both sides of the centre', function () {
    /*
     * The analytic mirror of the property `cluster-hex.test.ts` pins by painting: there, the six cells
     * `hexCentre()` draws touching a hex must be exactly the six `hexDistance()` calls adjacent. PHP
     * paints nothing, so the shape is asserted directly — and it is asserted over the *whole* disc
     * rather than a sample, because the bug this guards against is a parity shear that leaves one half
     * of the map perfectly self-consistent.
     *
     * "Distance 1 means drawn adjacent" plus the cube axes summing to zero determines the metric, so
     * an implementation that passes this necessarily agrees with the TypeScript one.
     */
    $radius = ClusterGenerator::RADIUS;

    for ($x = -$radius; $x <= $radius; $x++) {
        for ($y = -$radius; $y <= $radius; $y++) {
            if ($x ** 2 + $y ** 2 > $radius ** 2) {
                continue;
            }

            $cell = new Coordinates($x, $y, 0);
            $neighbours = 0;

            /* Two rings out, so a metric that reported neighbours too far away would be caught too. */
            for ($dx = -2; $dx <= 2; $dx++) {
                for ($dy = -2; $dy <= 2; $dy++) {
                    if ($cell->hexDistanceTo(new Coordinates($x + $dx, $y + $dy, 0)) === 1) {
                        $neighbours++;
                    }
                }
            }

            expect($neighbours)->toBe(6, "({$x}, {$y}) has {$neighbours} neighbours");
        }
    }
});

test('hex distance never takes a shortcut through a third cell', function () {
    /*
     * The triangle inequality. A metric that satisfies it, is symmetric, and calls exactly the drawn
     * neighbours adjacent has nowhere left to be wrong.
     */
    $cells = array_slice(iterator_to_array(cellsAcrossTheCluster()), 0, 400);

    foreach ($cells as $index => $cell) {
        $via = $cells[($index * 7 + 13) % count($cells)];
        $to = $cells[($index * 31 + 5) % count($cells)];

        expect($cell->hexDistanceTo($to))
            ->toBeLessThanOrEqual($cell->hexDistanceTo($via) + $via->hexDistanceTo($to));
    }
});

test('hex distance matches the table pinned in cluster-hex.test.ts', function (int $ax, int $ay, int $bx, int $by, int $hexes) {
    /*
     * **This table is duplicated verbatim in `resources/js/lib/cluster-hex.test.ts`, and the two move
     * together.** A property both implementations satisfy is not a drift alarm — it says each one is
     * internally sound, not that they agree on a number. These pairs all straddle the parity boundary,
     * which is where they would disagree first: drop the `abs()` in either `toCube()` and the first row
     * reads 2 instead of 1.
     *
     * The first two rows are the ones `cluster-hex.test.ts` already pinned on its own, kept as-is so
     * this table is an extension of that file's coverage rather than a second opinion about it.
     */
    expect((new Coordinates($ax, $ay, 0))->hexDistanceTo(new Coordinates($bx, $by, 0)))->toBe($hexes);
})->with([
    [-1, -1, 0, 0, 1],
    [-7, -2, 3, 1, 10],
    [-3, 4, 3, -4, 11],
    [-1, 0, 1, 0, 2],
    [-5, -5, 5, 5, 15],
    [0, 0, 0, 7, 7],
    [-2, 3, -9, -3, 9],
    [7, -6, -8, 2, 15],
]);

test('the two distances answer different questions, and the hex one drops the height', function () {
    /*
     * The distinction this class now has to keep straight. `HomeStelliumGenerator` compares hexes and
     * `ClusterGenerator` compares squared three-dimensional distances; reading one as the other is the
     * mistake, and these two systems are far apart by one measure and identical by the other.
     */
    $low = new Coordinates(3, -4, -6);
    $high = new Coordinates(3, -4, 9);

    expect($low->hexDistanceTo($high))->toBe(0);
    expect($low->squaredDistanceTo($high))->toBe(225);
});

/**
 * Every cell of the cluster's footprint, as coordinates at height zero.
 *
 * @return Generator<int, Coordinates>
 */
function cellsAcrossTheCluster(): Generator
{
    $radius = ClusterGenerator::RADIUS;

    for ($x = -$radius; $x <= $radius; $x++) {
        for ($y = -$radius; $y <= $radius; $y++) {
            if ($x ** 2 + $y ** 2 <= $radius ** 2) {
                yield new Coordinates($x, $y, 0);
            }
        }
    }
}
