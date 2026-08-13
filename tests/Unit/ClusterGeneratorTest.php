<?php

use App\Generation\ClusterGenerator;
use App\Generation\Coordinates;
use App\Models\Game;

/*
|--------------------------------------------------------------------------
| The cluster generator
|--------------------------------------------------------------------------
|
| A Unit test rather than a Feature one, and that is the point of the class being pure: a seed goes
| in and a `LocationSet` comes out, with no database, no clock and no container anywhere near it.
| `tests/Pest.php` binds `RefreshDatabase` to the Feature suite only, so anything that reached for a
| model here would fail loudly rather than quietly work.
|
| Two kinds of assertion live here. The **constraints** — count, sphere, origin, separation — say the
| cluster is a legal one. The **determinism** assertions say it is the *same* legal one every time,
| which is the property the whole seed design exists to provide and the only one a reader cannot
| check by looking at a screen.
|
| The seeds below are fixed on purpose. A test drawing its own random seeds would be a different test
| on every run, which is exactly what this file is here to prevent elsewhere.
|
*/

/**
 * The seeds every constraint is checked against.
 *
 * More than one, because a single seed proves a single cluster: the constraints have to hold for any
 * seed, and the cheapest approximation of "any" is a handful that do not resemble each other.
 *
 * @return array<int, int>
 */
function clusterSeeds(): array
{
    return [0, 1, 4242, 65_535, 1_234_567, Game::SEED_MAX];
}

test('it places exactly the number of locations a cluster has', function (int $seed) {
    expect((new ClusterGenerator)->generate($seed)->count())
        ->toBe(ClusterGenerator::LOCATION_COUNT);
})->with(clusterSeeds());

test('every location is inside the sphere and none is at the origin', function (int $seed) {
    $cluster = (new ClusterGenerator)->generate($seed);

    foreach ($cluster->coordinates as $coordinates) {
        expect($coordinates->squaredRadius())->toBeLessThanOrEqual(ClusterGenerator::RADIUS ** 2);
        expect($coordinates->isOrigin())->toBeFalse();

        /* The bounding cube as well, which is what the coordinate columns are sized for. */
        foreach ([$coordinates->x, $coordinates->y, $coordinates->z] as $axis) {
            expect($axis)->toBeGreaterThanOrEqual(-ClusterGenerator::RADIUS);
            expect($axis)->toBeLessThanOrEqual(ClusterGenerator::RADIUS);
        }
    }
})->with(clusterSeeds());

test('no two locations are closer together than the minimum separation', function (int $seed) {
    /*
     * Compared as squares, exactly as the generator does it, so "at least 2" is decided in integer
     * arithmetic and a location exactly 2 away is accepted rather than lost to a rounded square root.
     */
    $cluster = (new ClusterGenerator)->generate($seed);
    $minimum = ClusterGenerator::MINIMUM_SEPARATION ** 2;

    foreach ($cluster->coordinates as $index => $coordinates) {
        foreach (array_slice($cluster->coordinates, $index + 1) as $other) {
            expect($coordinates->squaredDistanceTo($other))->toBeGreaterThanOrEqual($minimum);
        }
    }

    expect($cluster->minimumSeparation())->toBeGreaterThanOrEqual((float) ClusterGenerator::MINIMUM_SEPARATION);
})->with(clusterSeeds());

test('no location is repeated', function (int $seed) {
    /*
     * Implied by the separation rule — two identical points are zero apart — but asserted separately
     * because the database enforces it too, through the unique key on `(game_id, x, y, z)`, and a
     * generator that produced a duplicate would surface as a constraint violation rather than as a
     * cluster that is subtly wrong.
     */
    $cluster = (new ClusterGenerator)->generate($seed);

    $points = array_map(
        fn (Coordinates $coordinates): string => "{$coordinates->x},{$coordinates->y},{$coordinates->z}",
        $cluster->coordinates,
    );

    expect(array_unique($points))->toHaveCount(ClusterGenerator::LOCATION_COUNT);
})->with(clusterSeeds());

test('traveler mode gives every location a hex of its own', function (int $seed) {
    /*
     * The constraint itself: no two locations share an `(x, y)` pair, so the count of distinct pairs
     * is the location count. Separation does not imply this and cannot — two systems can be as far
     * apart as the cluster gets while standing in the same column, differing only in `z`.
     */
    $cluster = (new ClusterGenerator)->generate($seed, traveler: true);

    $columns = array_map(
        fn (Coordinates $coordinates): string => "{$coordinates->x},{$coordinates->y}",
        $cluster->coordinates,
    );

    expect(array_unique($columns))->toHaveCount(ClusterGenerator::LOCATION_COUNT);
    expect($cluster->occupiedHexes())->toBe(ClusterGenerator::LOCATION_COUNT);
})->with(clusterSeeds());

test('traveler mode leaves the whole centre column empty, not just the origin', function (int $seed) {
    /*
     * `isOrigin()` is not enough and never was: `(0, 0, -10)` is thirty units from the middle of the
     * cluster, is not the origin, and still lands in the hex the map presents as the empty centre.
     * "One system per hex" has to include the centre hex or the map contradicts itself.
     */
    $cluster = (new ClusterGenerator)->generate($seed, traveler: true);

    foreach ($cluster->coordinates as $coordinates) {
        expect($coordinates->isInCentreColumn())->toBeFalse();
    }
})->with(clusterSeeds());

test('the reported centre-hex system is gone from the seed that produced it', function () {
    /*
     * Seed 3332012312 put system #53 at `(0, 0, -10)` — a real traveler cluster from the screen, kept
     * as the case rather than trusted to the sweep above, since a rule is easiest to lose on the exact
     * input that first showed it was missing.
     */
    $cluster = (new ClusterGenerator)->generate(3332012312, traveler: true);

    $centre = array_filter(
        $cluster->coordinates,
        fn (Coordinates $coordinates): bool => $coordinates->isInCentreColumn(),
    );

    expect($centre)->toBeEmpty();
    expect($cluster->occupiedHexes())->toBe(ClusterGenerator::LOCATION_COUNT);
});

test('an ordinary cluster may still occupy the centre hex, and the map must not claim otherwise', function () {
    /*
     * The other side of the same fact, pinned so it cannot be forgotten. Only the *origin* is refused
     * without the traveler constraint, so a system sits in the centre hex in roughly one game in six —
     * measured at 17.8% over 400 seeds. `ClusterHexMap` therefore reads the centre off the locations
     * rather than asserting it is empty, and widening this rejection to every cluster would change the
     * draw for that share of seeds and break their stored clusters.
     */
    $occupied = 0;

    foreach (range(1, 60) as $seed) {
        foreach ((new ClusterGenerator)->generate($seed)->coordinates as $coordinates) {
            expect($coordinates->isOrigin())->toBeFalse();

            if ($coordinates->isInCentreColumn()) {
                $occupied++;

                break;
            }
        }
    }

    expect($occupied)->toBeGreaterThan(0);
});

test('a traveler cluster is still a legal cluster in every other way', function (int $seed) {
    /*
     * The extra rule is a fourth rejection rather than a different algorithm, so nothing above may
     * lapse: a traveler cluster is full, inside the sphere, off the origin and properly separated.
     */
    $cluster = (new ClusterGenerator)->generate($seed, traveler: true);

    expect($cluster->count())->toBe(ClusterGenerator::LOCATION_COUNT);
    expect($cluster->minimumSeparation())->toBeGreaterThanOrEqual((float) ClusterGenerator::MINIMUM_SEPARATION);
    expect($cluster->attempts)->toBeLessThan(ClusterGenerator::MAXIMUM_ATTEMPTS);

    foreach ($cluster->coordinates as $coordinates) {
        expect($coordinates->squaredRadius())->toBeLessThanOrEqual(ClusterGenerator::RADIUS ** 2);
        expect($coordinates->isOrigin())->toBeFalse();
    }
})->with(clusterSeeds());

test('an ordinary cluster does stack systems, which is what traveler mode is for', function () {
    /*
     * The other half of the pair. Without the constraint a cluster occupies *fewer* hexes than it has
     * locations — about seven a game land on a column somebody already holds — and if this ever stops
     * being true then traveler mode is solving a problem that no longer exists, and the hex map's
     * stacking code has become dead weight rather than load-bearing.
     */
    $cluster = (new ClusterGenerator)->generate(4242);

    expect($cluster->occupiedHexes())->toBeLessThan(ClusterGenerator::LOCATION_COUNT);
});

test('leaving traveler mode off draws exactly what it always drew', function () {
    /*
     * **The regression guard for every seed already accepted into a game.** The coordinates below were
     * produced before traveler mode existed, and they are pinned as literals rather than compared to
     * another run of the same code — a shifted draw stream would agree with itself perfectly and still
     * have silently renumbered every stored cluster in the database.
     *
     * It holds because no rejection consumes a draw: the three `getInt` calls are unconditional and in
     * a fixed order, so a test that never fires cannot move the sequence. Drawing a coordinate only
     * after an earlier one passed would break this, which is the point of pinning it.
     */
    $cluster = (new ClusterGenerator)->generate(4242);

    $first = array_map(
        fn (Coordinates $coordinates): array => [$coordinates->x, $coordinates->y, $coordinates->z],
        array_slice($cluster->coordinates, 0, 3),
    );

    expect($first)->toBe([[-13, -2, -7], [7, -9, 7], [4, -1, 10]]);
    expect($cluster->attempts)->toBe(253);

    /* And the default argument is the same thing as passing it off. */
    expect($cluster->coordinates)->toEqual((new ClusterGenerator)->generate(4242, traveler: false)->coordinates);
});

test('traveler mode changes the cluster a seed produces', function () {
    /*
     * A constraint that rejected nothing would pass every assertion above by drawing the ordinary
     * cluster. Rejections cost draws, so the two runs diverge from the first stacked candidate on.
     */
    $ordinary = (new ClusterGenerator)->generate(4242);
    $traveler = (new ClusterGenerator)->generate(4242, traveler: true);

    expect($traveler->coordinates)->not->toEqual($ordinary->coordinates);
    expect($traveler->attempts)->toBeGreaterThan($ordinary->attempts);
});

test('the same seed produces the identical cluster, in the identical order', function () {
    /*
     * **The assertion the seed exists for.** Order is part of it, not an afterthought: a location's
     * ordinal is its place in this list, so a generator that returned the same points in a different
     * order would renumber the game while looking correct.
     */
    $first = (new ClusterGenerator)->generate(4242);
    $second = (new ClusterGenerator)->generate(4242);

    expect($first->coordinates)->toEqual($second->coordinates);
    expect($first->attempts)->toBe($second->attempts);
});

test('a different seed produces a different cluster', function () {
    /* The other half: a seed that is ignored would pass every test above and none of this one. */
    $first = (new ClusterGenerator)->generate(4242);
    $second = (new ClusterGenerator)->generate(4243);

    expect($first->coordinates)->not->toEqual($second->coordinates);
});

test('a fresh generator instance is not carrying state between runs', function () {
    /*
     * Two runs from *different* instances must agree, which is what says the randomizer is built from
     * the seed each time rather than held on the object.
     */
    expect((new ClusterGenerator)->generate(7)->coordinates)
        ->toEqual((new ClusterGenerator)->generate(7)->coordinates);
});

test('the summary describes the cluster it came from', function () {
    $cluster = (new ClusterGenerator)->generate(4242);
    $summary = $cluster->summary();

    expect($summary['locations'])->toBe(ClusterGenerator::LOCATION_COUNT);
    expect($summary['minimum_separation'])->toBeGreaterThanOrEqual(2.0);

    /*
     * Measured from the points, not reported by the generator, which is why it can be pinned to a
     * literal: 95 of this seed's hundred locations stand in a hex of their own and the other five are
     * stacked in pairs. Under traveler mode the same seed fills all hundred.
     */
    expect($summary['occupied_hexes'])->toBe(95);
    expect((new ClusterGenerator)->generate(4242, traveler: true)->summary()['occupied_hexes'])
        ->toBe(ClusterGenerator::LOCATION_COUNT);

    expect($summary['maximum_radius'])->toBeLessThanOrEqual((float) ClusterGenerator::RADIUS);

    /*
     * Attempts exceed the count — there is always some rejection — but stay far below the cap. The
     * measured worst case over two thousand seeds was in the hundreds against a cap of 100,000.
     */
    expect($summary['attempts'])->toBeGreaterThan(ClusterGenerator::LOCATION_COUNT);
    expect($summary['attempts'])->toBeLessThan(ClusterGenerator::MAXIMUM_ATTEMPTS);
});

test('the constants stay inside what the schema and the seed range can hold', function () {
    /*
     * The coordinate columns are `tinyInteger`, and a seed is a 32-bit unsigned integer because that
     * is what the engine takes. Neither is a limit the generator can be changed past without a
     * migration, so the relationship is pinned rather than left as a comment.
     */
    expect(ClusterGenerator::RADIUS)->toBeLessThanOrEqual(127);
    expect(ClusterGenerator::MINIMUM_SEPARATION)->toBeGreaterThan(0);
    expect(ClusterGenerator::LOCATION_COUNT)->toBeGreaterThan(0);
});
