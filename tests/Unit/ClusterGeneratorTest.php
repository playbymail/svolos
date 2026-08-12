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
