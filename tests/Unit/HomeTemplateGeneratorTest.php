<?php

use App\Enums\PlanetType;
use App\Generation\HomeTemplateGenerator;
use App\Generation\HomeTemplatePlanet;
use App\Generation\PlanetGenerator;
use App\Models\Game;

/*
|--------------------------------------------------------------------------
| Drawing a home template
|--------------------------------------------------------------------------
|
| The generated half of the template stage, for a gamemaster with no document to upload. It is the odd
| generator of the five: most of what it produces is **fixed**, and only some of the numbers are drawn.
| That inversion is the feature rather than a shortcut — a template exists to make the start of a game
| the same for everybody, so the shape of a home is a decision the game makes once.
|
| What the seed decides is what the shape is worth: the eight neighbours' habitability and the home
| world's three deposits. So two games differ from each other and every player inside one game does
| not, which is the whole contract of the stage.
|
*/

test('the arrangement is nine planets, in the same order every time', function (int $seed) {
    $template = (new HomeTemplateGenerator)->generate($seed);

    expect(array_map(
        fn (HomeTemplatePlanet $planet): PlanetType => $planet->type,
        $template->planets,
    ))->toBe(HomeTemplateGenerator::ARRANGEMENT);

    expect($template->planets)->toHaveCount(9);
})->with([0, 1, 4242, 65_535, Game::SEED_MAX]);

test('the arrangement follows the zoning the cluster itself is drawn against', function () {
    /*
     * Not an arbitrary list: rocky worlds inside, ice outside, belts between. A home that could never
     * have come out of `PlanetGenerator` would read as a special case somebody bolted on, and the
     * point of a template is to settle a home rather than to make it strange.
     */
    $types = HomeTemplateGenerator::ARRANGEMENT;

    expect($types[0])->toBe(PlanetType::Rocky);
    expect(array_slice($types, -1))->toBe([PlanetType::Icy]);
    expect(count($types))->toBe(PlanetGenerator::SOLAR_SYSTEM_ORBITS);
});

test('the home world is the third planet, at the top of the habitability scale', function (int $seed) {
    $template = (new HomeTemplateGenerator)->generate($seed);

    expect($template->homeOrdinal())->toBe(HomeTemplateGenerator::HOME_ORDINAL);
    expect($template->home()->habitability)->toBe(HomeTemplateGenerator::HOME_HABITABILITY);
    expect($template->home()->type)->toBe(PlanetType::Rocky);

    /* The maximum the rocky dice reach, so a home world is never merely adequate. */
    [$dice, $sides, $modifier] = PlanetGenerator::HABITABILITY_DICE[PlanetType::Rocky->value];

    expect(HomeTemplateGenerator::HOME_HABITABILITY)->toBe($dice * $sides + $modifier);
})->with([0, 4242, 999_999]);

test('every other planet has a habitability its own type could have been drawn', function (int $seed) {
    $template = (new HomeTemplateGenerator)->generate($seed);

    foreach ($template->planets as $index => $planet) {
        if ($index + 1 === HomeTemplateGenerator::HOME_ORDINAL) {
            continue;
        }

        [$dice, $sides, $modifier] = PlanetGenerator::HABITABILITY_DICE[$planet->type->value];

        expect($planet->habitability)
            ->toBeGreaterThanOrEqual($dice + $modifier)
            ->toBeLessThanOrEqual($dice * $sides + $modifier);
    }
})->with([0, 1, 4242, 65_535, 999_999]);

test('an asteroid belt is never habitable, here as anywhere else', function (int $seed) {
    /*
     * It falls out of the shared dice table rather than being restated — the zero is a row in
     * `PlanetGenerator::HABITABILITY_DICE`, not a branch here — and this is what says so.
     */
    $template = (new HomeTemplateGenerator)->generate($seed);

    foreach ($template->planets as $planet) {
        if ($planet->type === PlanetType::Asteroids) {
            expect($planet->habitability)->toBe(0);
        }
    }
})->with([0, 4242, 999_999]);

test('only the home world has its deposits settled', function () {
    $template = (new HomeTemplateGenerator)->generate(4242);

    foreach ($template->planets as $index => $planet) {
        expect($planet->isHome())->toBe($index + 1 === HomeTemplateGenerator::HOME_ORDINAL);
    }

    $home = $template->home();

    /* Drawn from the rocky deposit table, so a home world looks like a rocky world. */
    foreach (['fuel' => $home->fuel, 'metals' => $home->metals, 'minerals' => $home->minerals] as $deposit => $value) {
        [$dice, $sides, $modifier] = PlanetGenerator::DEPOSIT_DICE[PlanetType::Rocky->value][$deposit];

        expect($value)
            ->toBeGreaterThanOrEqual($dice + $modifier)
            ->toBeLessThanOrEqual($dice * $sides + $modifier);
    }
});

test('a generated template remembers no document', function () {
    /* Null `file` is how the screen tells a drawn template from an uploaded one. */
    expect((new HomeTemplateGenerator)->generate(4242)->file)->toBeNull();
    expect((new HomeTemplateGenerator)->generate(4242)->summary()['file'])->toBeNull();
});

test('the same seed gives the same template', function (int $seed) {
    $generator = new HomeTemplateGenerator;

    expect($generator->generate($seed)->toArray())->toBe($generator->generate($seed)->toArray());
})->with([0, 1, 4242, Game::SEED_MAX]);

test('a different seed gives a different home world', function () {
    /*
     * The reason this draws at all. Asserted across several seeds rather than a pair, because two
     * templates differing once is luck: the home world has three deposits and could coincide.
     */
    $generator = new HomeTemplateGenerator;

    $homes = array_map(
        fn (int $seed): array => [
            $generator->generate($seed)->home()->fuel,
            $generator->generate($seed)->home()->metals,
            $generator->generate($seed)->home()->minerals,
        ],
        [0, 1, 4242, 65_535, 999_999, 1_234_567],
    );

    expect(count(array_unique(array_map('serialize', $homes))))->toBeGreaterThan(1);
});

test('the generator carries no state between runs', function () {
    /*
     * One instance is resolved out of the container and reused across a request, so a generator that
     * accumulated anything would make the second template of a session differ from the first for the
     * same seed — the same guard the other four generators carry.
     */
    $generator = new HomeTemplateGenerator;

    $first = $generator->generate(4242)->toArray();
    $generator->generate(7);
    $generator->generate(999);

    expect($generator->generate(4242)->toArray())->toBe($first);
});
