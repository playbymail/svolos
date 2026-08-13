<?php

use App\Enums\PlanetType;
use App\Generation\GenerationFailed;
use App\Generation\HomeTemplate;

/*
|--------------------------------------------------------------------------
| Reading a home template somebody wrote
|--------------------------------------------------------------------------
|
| A gamemaster uploads this file, so most of these tests are about the ways it can be wrong. Every one
| of them asserts two things together: the sentence a gamemaster is shown, and that the failure carries
| `template` as its field. The second half is what puts the first on the form beside the file input
| rather than on an error page — `GenerationController` reads `$field` and nothing else — so a message
| tested without it is a message nobody would ever see in the right place.
|
| The bounds are the column's rather than the generator's, which is the one rule here that reads like
| an oversight and is not: a drawn planet must stay inside its distribution, while a template is
| somebody's deliberate choice and is held only to what the row can physically hold.
|
*/

/**
 * Write a template document, with one planet overridden.
 *
 * Nine planets is what a generated template produces, so a valid document here is the same shape as a
 * drawn one and the tests below differ from it in exactly one thing at a time.
 *
 * @param  array<string, mixed>  $overrides
 */
function templateJson(array $overrides = [], ?int $replace = null): string
{
    $planets = [
        ['ordinal' => 1, 'type' => 'rocky', 'habitability' => 3],
        ['ordinal' => 2, 'type' => 'rocky', 'habitability' => 8],
        ['ordinal' => 3, 'type' => 'rocky', 'habitability' => 25, 'home' => true, 'fuel' => 5, 'metals' => 5, 'minerals' => 5],
        ['ordinal' => 4, 'type' => 'asteroids', 'habitability' => 0],
        ['ordinal' => 5, 'type' => 'gas_giant', 'habitability' => 2],
        ['ordinal' => 6, 'type' => 'icy', 'habitability' => 4],
        ['ordinal' => 7, 'type' => 'icy', 'habitability' => 1],
        ['ordinal' => 8, 'type' => 'asteroids', 'habitability' => 0],
        ['ordinal' => 9, 'type' => 'icy', 'habitability' => 6],
    ];

    if ($replace !== null) {
        $planets[$replace - 1] = $overrides;
    }

    return (string) json_encode(['planets' => $planets]);
}

test('a well-formed document is read into a template', function () {
    $template = HomeTemplate::fromJson(templateJson(), 'homeworld.json');

    expect($template->planets)->toHaveCount(9);
    expect($template->file)->toBe('homeworld.json');
    expect($template->homeOrdinal())->toBe(3);

    $home = $template->home();

    expect($home->type)->toBe(PlanetType::Rocky);
    expect($home->habitability)->toBe(25);
    expect([$home->fuel, $home->metals, $home->minerals])->toBe([5, 5, 5]);

    /* Every other planet leaves its deposits open, which is what makes them per-player. */
    foreach ($template->planets as $ordinal => $planet) {
        expect($planet->isHome())->toBe($ordinal + 1 === 3);
    }
});

test('a template survives the round trip through the run it is stored on', function () {
    /*
     * `toArray()` goes into a json column and `fromArray()` reads it back out, and the planets stage
     * reads the result rather than the original — so a lossy trip would silently give every player a
     * different home from the one the gamemaster uploaded.
     */
    $template = HomeTemplate::fromJson(templateJson(), 'homeworld.json');

    $restored = HomeTemplate::fromArray($template->toArray());

    expect($restored->toArray())->toBe($template->toArray());
    expect($restored->file)->toBe('homeworld.json');
    expect($restored->home()->minerals)->toBe(5);
    expect($restored->planets[0]->fuel)->toBeNull();
});

test('the summary describes the template for the card that reviews it', function () {
    $summary = HomeTemplate::fromJson(templateJson(), 'homeworld.json')->summary();

    expect($summary['file'])->toBe('homeworld.json');
    expect($summary['planets'])->toBe(9);
    expect($summary['home_ordinal'])->toBe(3);
    expect($summary['home_habitability'])->toBe(25);

    /*
     * `types` rather than `mix`, and the name is load-bearing: `GenerationStageCard` special-cases a
     * key called `mix` by appending "star" to each of its own keys, because a stellium mix is keyed by
     * how many stars a stellium holds. Keyed by planet type that renders "rocky stars 3".
     */
    expect($summary['types'])->toBe(['rocky' => 3, 'asteroids' => 2, 'gas_giant' => 1, 'icy' => 3]);
});

test('a document that is not json is refused', function () {
    expect(fn () => HomeTemplate::fromJson('not a document at all', 'notes.txt'))
        ->toThrow(GenerationFailed::class, 'not readable as JSON');
});

test('a document with no planets list is refused', function (string $json) {
    expect(fn () => HomeTemplate::fromJson($json, 'homeworld.json'))
        ->toThrow(GenerationFailed::class, 'needs a "planets" list');
})->with([
    'nothing at all' => '{}',
    'the wrong shape' => '{"planets": {"1": {"type": "rocky"}}}',
    'not a list' => '{"planets": "rocky"}',
]);

test('a system of the wrong size is refused', function (int $count) {
    $planets = array_map(
        fn (int $ordinal): array => ['ordinal' => $ordinal, 'type' => 'rocky', 'habitability' => 1],
        range(1, max($count, 1)),
    );

    /* Marked so the size is the only thing wrong with it, rather than the missing home world. */
    $planets[0]['home'] = true;
    $planets[0] += ['fuel' => 1, 'metals' => 1, 'minerals' => 1];

    $json = (string) json_encode(['planets' => $count === 0 ? [] : $planets]);

    expect(fn () => HomeTemplate::fromJson($json, 'homeworld.json'))
        ->toThrow(GenerationFailed::class, 'A home system has between 1 and 10 planets');
})->with([
    'empty' => 0,
    'one too many' => 11,
    'far too many' => 40,
]);

test('a planet numbered out of order is refused', function () {
    expect(fn () => HomeTemplate::fromJson(
        templateJson(['ordinal' => 5, 'type' => 'rocky', 'habitability' => 2], replace: 4),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'Planet 4 is numbered 5');
});

test('a planet of an unknown type is refused', function () {
    expect(fn () => HomeTemplate::fromJson(
        templateJson(['ordinal' => 4, 'type' => 'ringworld', 'habitability' => 2], replace: 4),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'Planet 4 has no usable type');
});

test('a planet whose numbers are missing or out of range is refused', function (mixed $habitability) {
    expect(fn () => HomeTemplate::fromJson(
        templateJson(array_filter([
            'ordinal' => 4,
            'type' => 'rocky',
            'habitability' => $habitability,
        ], fn (mixed $value): bool => $value !== null), replace: 4),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'Planet 4 needs a whole habitability between 0 and 255');
})->with([
    'missing' => null,
    'negative' => -1,
    'past the column' => 256,
    'not a whole number' => 4.5,
    'a word' => 'high',
]);

test('a template that marks no home world is refused', function () {
    expect(fn () => HomeTemplate::fromJson(
        templateJson(['ordinal' => 3, 'type' => 'rocky', 'habitability' => 25], replace: 3),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'No planet is marked as the home world');
});

test('a template that marks two home worlds is refused', function () {
    /*
     * Named rather than counted, because the mistake is almost always a copied line and the useful
     * half of the sentence is *which* two.
     */
    expect(fn () => HomeTemplate::fromJson(
        templateJson(
            ['ordinal' => 6, 'type' => 'icy', 'habitability' => 4, 'home' => true, 'fuel' => 1, 'metals' => 1, 'minerals' => 1],
            replace: 6,
        ),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'Planets 3 and 6 are both marked as the home world');
});

test('deposits on a planet that is not the home world are refused rather than ignored', function () {
    /*
     * The refusal that reads as unhelpful and is the point: these are drawn for each player, so a
     * document setting them is describing something it does not control. Dropping them quietly would
     * leave a gamemaster believing they had fixed their neighbours' mining.
     */
    expect(fn () => HomeTemplate::fromJson(
        templateJson(['ordinal' => 2, 'type' => 'rocky', 'habitability' => 8, 'metals' => 30], replace: 2),
        'homeworld.json',
    ))->toThrow(GenerationFailed::class, 'Planet 2 sets its metals');
});

test('every refusal lands on the template field', function (string $json) {
    /*
     * Asserted over all of them at once rather than in each test above, because it is one property of
     * the whole parser: `GenerationController` puts the message on `$failure->field`, so a refusal
     * that forgot it would be reported against the seed — a number that had nothing to do with it.
     */
    try {
        HomeTemplate::fromJson($json, 'homeworld.json');
    } catch (GenerationFailed $failure) {
        expect($failure->field)->toBe('template');

        return;
    }

    $this->fail('The document was accepted.');
})->with([
    'unreadable' => 'not a document at all',
    'no planets' => '{}',
    'empty' => '{"planets": []}',
    'no home world' => '{"planets": [{"ordinal": 1, "type": "rocky", "habitability": 4}]}',
    'unknown type' => '{"planets": [{"ordinal": 1, "type": "ringworld", "habitability": 4, "home": true, "fuel": 1, "metals": 1, "minerals": 1}]}',
]);

test('a single-planet template is allowed, because the dice can produce one', function () {
    /*
     * The bound comes from `PlanetGenerator::PLANET_DICE` rather than from taste: a home has to be a
     * system the cluster itself could have contained, and a one-planet system is one of those.
     */
    $template = HomeTemplate::fromJson(
        '{"planets": [{"ordinal": 1, "type": "rocky", "habitability": 25, "home": true, "fuel": 2, "metals": 3, "minerals": 4}]}',
        'spartan.json',
    );

    expect($template->planets)->toHaveCount(1);
    expect($template->homeOrdinal())->toBe(1);
});

test('the ordinal may be left out entirely, since the order is the document\'s own', function () {
    /*
     * The list's position is what is kept; the `ordinal` key is a check on it, not the source of it.
     * Leaving it out is therefore fine, and writing a wrong one is not — the two halves of one rule.
     */
    $template = HomeTemplate::fromJson(
        '{"planets": [{"type": "rocky", "habitability": 25, "home": true, "fuel": 2, "metals": 3, "minerals": 4}]}',
        'spartan.json',
    );

    expect($template->homeOrdinal())->toBe(1);
});
