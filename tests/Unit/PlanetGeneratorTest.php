<?php

use App\Enums\PlanetType;
use App\Generation\PlanetGenerator;
use App\Generation\PlanetPlan;
use App\Generation\PlanetProfile;
use App\Generation\PlanetSystem;

/*
|--------------------------------------------------------------------------
| The planet generator
|--------------------------------------------------------------------------
|
| Two things are worth pinning here, and neither is obvious from reading the class.
|
| The first is the **zone table**: which zones a star of each size actually reaches. The belt is one
| ninth wide, so a star with fewer than nine planets skips zones, and which ones it skips is not
| something anybody should have to re-derive from the arithmetic. A lone planet landing in the belt —
| and so usually being an asteroid field — reads like a bug until you see it written down as intended.
|
| The second is the **mix**. The type weights are tuned against the measured zone shares rather than
| against four equal quarters, so the numbers in `ZONE_WEIGHTS` do not resemble the solar system's
| proportions even though what comes out of them does. Change a weight and this test says by how much
| the world moved.
|
*/

/**
 * Seeds the constraint tests run against.
 *
 * @return array<int, int>
 */
function planetSeeds(): array
{
    return [0, 1, 4242, 65_535, 1_234_567];
}

/**
 * Count how many planets of a given star size land in each zone.
 *
 * @return array<int, int> zone index => planets
 */
function zoneCountsFor(int $count): array
{
    $generator = new PlanetGenerator;
    $zones = array_fill(0, count(PlanetGenerator::ZONE_BOUNDARIES) + 1, 0);

    for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
        $zones[$generator->zoneFor($ordinal, $count)]++;
    }

    return $zones;
}

test('a star with nine planets is arranged exactly like the solar system', function () {
    /*
     * Four rocky orbits, the belt, two gas giants, two ice giants. This is where the zone boundaries
     * come from, so it is the one arrangement that has to come back out of them unchanged.
     */
    expect(zoneCountsFor(9))->toBe([0 => 4, 1 => 1, 2 => 2, 3 => 2]);
});

test('the zones a star reaches depend on how many planets it has', function (int $count, array $zones) {
    expect(zoneCountsFor($count))->toBe($zones);
})->with([
    /* A lone planet sits at the middle of its system, which is the belt — so it is usually asteroids. */
    [1, [0 => 0, 1 => 1, 2 => 0, 3 => 0]],
    [2, [0 => 1, 1 => 0, 2 => 1, 3 => 0]],
    /* Three planets never reach the outer zone, so gas giants are rare around those stars. */
    [3, [0 => 1, 1 => 1, 2 => 0, 3 => 1]],
    [4, [0 => 2, 1 => 0, 2 => 1, 3 => 1]],
    [5, [0 => 2, 1 => 1, 2 => 1, 3 => 1]],
    [6, [0 => 3, 1 => 0, 2 => 2, 3 => 1]],
    [7, [0 => 3, 1 => 1, 2 => 1, 3 => 2]],
    [8, [0 => 4, 1 => 0, 2 => 2, 3 => 2]],
    [9, [0 => 4, 1 => 1, 2 => 2, 3 => 2]],
    [10, [0 => 4, 1 => 2, 2 => 2, 3 => 2]],
]);

test('the zones are not four equal quarters', function () {
    /*
     * Weighting each zone by how many planets land in it across the whole 3d4 − 2 distribution. These
     * are the shares `ZONE_WEIGHTS` is tuned against; assuming quarters instead would pull the mix
     * several points off. 352 is the total planet-slots per 64 stars — 64 × the mean of 5.5.
     */
    $slots = array_fill(0, 4, 0);

    foreach (range(1, 10) as $count) {
        /* How many of the 64 outcomes of 3d4 give this many planets. */
        $ways = [1, 3, 6, 10, 12, 12, 10, 6, 3, 1][$count - 1];

        foreach (zoneCountsFor($count) as $zone => $planets) {
            $slots[$zone] += $ways * $planets;
        }
    }

    expect($slots)->toBe([159, 34, 79, 80]);
    expect(array_sum($slots))->toBe(352);
});

test('every zone row is a whole hundred', function () {
    /*
     * `pick()` rolls against the row's own sum, so a row summing to 99 cannot roll off the end — but it
     * would silently mean something other than the percentages it is written as.
     */
    foreach (PlanetGenerator::ZONE_WEIGHTS as $zone => $weights) {
        expect(array_sum($weights))->toBe(100, "zone {$zone}");
        expect(array_keys($weights))->toBe(array_column(PlanetType::cases(), 'value'));
    }
});

test('there is one more zone than there are boundaries', function () {
    expect(PlanetGenerator::ZONE_WEIGHTS)->toHaveCount(count(PlanetGenerator::ZONE_BOUNDARIES) + 1);
});

test('the mix follows the solar system across a whole cluster', function (int $seed) {
    /*
     * 4 rocky / 1 belt / 2 gas / 2 ice out of nine bodies is 44.4 / 11.1 / 22.2 / 22.2 percent. The
     * zone boundaries do nearly all of the work of hitting that; the weights only sharpen it. Three
     * points of tolerance covers the sampling noise at 141 stars — the deterministic part of the design
     * is pinned by the zone tests above, not by this one.
     */
    $plan = (new PlanetGenerator)->generate($seed, 141);

    $total = $plan->planetTotal();

    $share = fn (PlanetType $type): float => 100 * $plan->mix()[$type->value] / $total;

    expect($share(PlanetType::Rocky))->toBeGreaterThan(41)->toBeLessThan(47);
    expect($share(PlanetType::Asteroids))->toBeGreaterThan(8)->toBeLessThan(14);
    expect($share(PlanetType::GasGiant))->toBeGreaterThan(19)->toBeLessThan(26);
    expect($share(PlanetType::Icy))->toBeGreaterThan(19)->toBeLessThan(26);
})->with(planetSeeds());

test('every star gets one to ten planets', function (int $seed) {
    $plan = (new PlanetGenerator)->generate($seed, 141);

    expect($plan->count())->toBe(141);

    foreach ($plan->systems as $system) {
        expect($system->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10);
    }

    /* 141 stars averaging 5.5 planets. Wide bounds: this is a sanity check, not a distribution test. */
    expect($plan->planetTotal())->toBeGreaterThan(600)->toBeLessThan(950);
})->with(planetSeeds());

test('every planet is inside the range its column can hold', function (int $seed) {
    $plan = (new PlanetGenerator)->generate($seed, 141);

    foreach ($plan->planets() as $planet) {
        expect($planet->habitability)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(25);

        foreach (['fuel', 'metals', 'minerals'] as $deposit) {
            expect($planet->{$deposit})->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(255);
        }
    }
})->with(planetSeeds());

test('an asteroid field is never habitable, and is the richest thing to mine', function (int $seed) {
    /*
     * The trade the design rests on: asteroids give up habitability entirely and are paid for it in
     * metals and minerals. Both halves are asserted together, because removing either one alone is the
     * change that quietly makes asteroids pointless.
     */
    $plan = (new PlanetGenerator)->generate($seed, 141);

    $asteroids = array_filter(
        $plan->planets(),
        fn (PlanetProfile $planet): bool => $planet->type === PlanetType::Asteroids,
    );

    expect($asteroids)->not->toBeEmpty();

    foreach ($asteroids as $planet) {
        expect($planet->habitability)->toBe(0);
        expect($planet->metals)->toBeGreaterThanOrEqual(10);
        expect($planet->minerals)->toBeGreaterThanOrEqual(10);
    }
})->with(planetSeeds());

test('every dice table stays inside a byte and never goes negative', function () {
    /*
     * The columns are `unsignedTinyInteger` and SQLite does not enforce that, so a negative modifier
     * introduced later would store silently and only surface on another driver. Checked against the
     * tables rather than against generated output, so a type that happens not to come up is still
     * covered.
     */
    $bounds = function (array $dice): array {
        [$count, $sides, $modifier] = $dice;

        return [$count + $modifier, $count * $sides + $modifier];
    };

    foreach (PlanetGenerator::HABITABILITY_DICE as $type => $dice) {
        [$minimum, $maximum] = $bounds($dice);

        expect($minimum)->toBeGreaterThanOrEqual(0, $type);
        expect($maximum)->toBeLessThanOrEqual(25, $type);
    }

    foreach (PlanetGenerator::DEPOSIT_DICE as $type => $deposits) {
        expect(array_keys($deposits))->toBe(['fuel', 'metals', 'minerals'], $type);

        foreach ($deposits as $deposit => $dice) {
            [$minimum, $maximum] = $bounds($dice);

            expect($minimum)->toBeGreaterThanOrEqual(0, "{$type} {$deposit}");
            expect($maximum)->toBeLessThanOrEqual(255, "{$type} {$deposit}");
        }
    }
});

test('rocky worlds reach both ends of the habitability scale', function () {
    /*
     * The point of 5d6 − 5: a rocky world can be dead *and* can be the best place in the cluster. A
     * table with a positive floor would make every rocky world habitable, which is the mistake this
     * pins against.
     */
    [$count, $sides, $modifier] = PlanetGenerator::HABITABILITY_DICE[PlanetType::Rocky->value];

    expect($count + $modifier)->toBe(0);
    expect($count * $sides + $modifier)->toBe(25);
});

test('the same seed builds the same worlds, and a neighbouring seed does not', function () {
    $flatten = fn (PlanetPlan $plan): array => array_map(
        fn (PlanetProfile $planet): array => [
            $planet->type->value, $planet->habitability, $planet->fuel, $planet->metals, $planet->minerals,
        ],
        $plan->planets(),
    );

    $first = (new PlanetGenerator)->generate(4242, 141);
    $second = (new PlanetGenerator)->generate(4242, 141);
    $different = (new PlanetGenerator)->generate(4243, 141);

    expect($flatten($first))->toBe($flatten($second));

    /* Sizes too, not just attributes: the planet count is drawn from the same stream. */
    expect(array_map(fn (PlanetSystem $system): int => $system->count(), $first->systems))
        ->toBe(array_map(fn (PlanetSystem $system): int => $system->count(), $second->systems));

    expect($flatten($different))->not->toBe($flatten($first));
});

test('a generator carries no state from one run to the next', function () {
    /*
     * The same instance twice must equal two instances once. A generator that cached its randomizer
     * would pass every other test in this file and fail this one.
     */
    $generator = new PlanetGenerator;

    $first = $generator->generate(4242, 20);
    $second = $generator->generate(4242, 20);

    expect($first->summary())->toBe($second->summary());
    expect($second->summary())->toBe((new PlanetGenerator)->generate(4242, 20)->summary());
});

test('the summary describes what the plan holds', function () {
    $plan = (new PlanetGenerator)->generate(4242, 141);

    $summary = $plan->summary();

    expect($summary['stars'])->toBe(141);
    expect($summary['planets'])->toBe($plan->planetTotal());
    expect(array_sum($summary['types']))->toBe($summary['planets']);

    /* Every type named, including any that came out at zero. */
    expect(array_keys($summary['types']))->toBe(array_column(PlanetType::cases(), 'value'));

    expect($summary['habitable'])->toBe(count(array_filter(
        $plan->planets(),
        fn (PlanetProfile $planet): bool => $planet->habitability >= PlanetProfile::HABITABLE_FROM,
    )));
});

test('no stars means no planets rather than a failure', function () {
    /* Not a state the application reaches, but the boundary should answer rather than divide by zero. */
    $plan = (new PlanetGenerator)->generate(4242, 0);

    expect($plan->count())->toBe(0);
    expect($plan->planetTotal())->toBe(0);
    expect($plan->mix())->toBe([
        PlanetType::Rocky->value => 0,
        PlanetType::Asteroids->value => 0,
        PlanetType::GasGiant->value => 0,
        PlanetType::Icy->value => 0,
    ]);
});
