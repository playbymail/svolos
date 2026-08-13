<?php

use App\Enums\PlanetType;
use App\Generation\HomeTemplateGenerator;
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

/*
|--------------------------------------------------------------------------
| Systems somebody begins at
|--------------------------------------------------------------------------
|
| A star named in `$homeSystems` takes its planets from the game's home template instead of the dice:
| the same count, types and habitability for every player, so nobody starts with a better home than
| anybody else. Only the deposits are still drawn, which is the one thing a home is allowed to differ
| in — what it is worth to mine.
|
| The generator is told none of that. It is handed a list of planets and an index, and never learns
| what a "home" is, which is why the tests below are about *substitution* rather than about homes.
|
*/

test('a home system gets exactly the planets it was given', function () {
    $template = (new HomeTemplateGenerator)->generate(4242);

    $plan = (new PlanetGenerator)->generate(88_213, 6, [2 => $template->planets]);

    $home = $plan->systems[2];

    expect($home->count())->toBe(count($template->planets));

    foreach ($home->planets as $ordinal => $planet) {
        expect($planet->type)->toBe($template->planets[$ordinal]->type);
        expect($planet->habitability)->toBe($template->planets[$ordinal]->habitability);
    }
});

test('a home system still has its deposits drawn', function () {
    /*
     * The half of a home that is *not* settled in advance, and the reason the template does not simply
     * become the planets. Every deposit stays inside its own type's table, exactly as a drawn planet's
     * would.
     */
    $template = (new HomeTemplateGenerator)->generate(4242);

    $home = (new PlanetGenerator)->generate(88_213, 6, [0 => $template->planets])->systems[0];

    foreach ($home->planets as $planet) {
        foreach (['fuel' => $planet->fuel, 'metals' => $planet->metals, 'minerals' => $planet->minerals] as $deposit => $value) {
            [$dice, $sides, $modifier] = PlanetGenerator::DEPOSIT_DICE[$planet->type->value][$deposit];

            expect($value)
                ->toBeGreaterThanOrEqual($dice + $modifier)
                ->toBeLessThanOrEqual($dice * $sides + $modifier);
        }
    }
});

test('two home systems in one cluster differ only in what they are worth to mine', function () {
    /*
     * The requirement stated as directly as it can be: identical to look at, different to work. Both
     * halves matter — a generator that gave them the same deposits too would make the deposits
     * pointless, and one that redrew their types would give one player a better home.
     */
    $template = (new HomeTemplateGenerator)->generate(4242);

    $plan = (new PlanetGenerator)->generate(88_213, 20, [3 => $template->planets, 11 => $template->planets]);

    $shape = fn (PlanetSystem $system): array => array_map(
        fn (PlanetProfile $planet): array => [$planet->type->value, $planet->habitability],
        $system->planets,
    );

    $worth = fn (PlanetSystem $system): array => array_map(
        fn (PlanetProfile $planet): array => [$planet->fuel, $planet->metals, $planet->minerals],
        $system->planets,
    );

    expect($shape($plan->systems[3]))->toBe($shape($plan->systems[11]));
    expect($worth($plan->systems[3]))->not->toBe($worth($plan->systems[11]));
});

test('naming no home systems leaves the cluster exactly as it was drawn before', function (int $seed) {
    /*
     * The compatibility guard for the parameter itself. Every pinned table and mix in this file is
     * asserted against the two-argument call, so a default that quietly changed the stream would break
     * them all at once and this says which change did it.
     */
    $generator = new PlanetGenerator;

    expect($generator->generate($seed, 141, [])->summary())
        ->toBe($generator->generate($seed, 141)->summary());
})->with(planetSeeds());

test('a home system is drawn from the same stream as everything around it', function () {
    /*
     * Not a second randomizer and not a second seed: naming a home changes what the stars *after* it
     * get, because the draws it makes come out of the one stream in star order. A generator that gave
     * home systems their own stream would leave the rest of the cluster identical, which is what this
     * refuses.
     */
    $template = (new HomeTemplateGenerator)->generate(4242);
    $generator = new PlanetGenerator;

    $without = $generator->generate(88_213, 10);
    $with = $generator->generate(88_213, 10, [0 => $template->planets]);

    /* The last star in the cluster, as far downstream of the substitution as this plan reaches. */
    $later = fn (PlanetPlan $plan): array => array_map(
        fn (PlanetProfile $planet): array => [$planet->type->value, $planet->habitability, $planet->fuel],
        $plan->systems[9]->planets,
    );

    expect($later($with))->not->toBe($later($without));
});
