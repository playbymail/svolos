<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Generation\GenerationFailed;
use App\Generation\Kit;
use App\Generation\KitGenerator;
use App\Generation\StartingUnits;
use App\Models\Game;

/*
|--------------------------------------------------------------------------
| Reading and writing a kit
|--------------------------------------------------------------------------
|
| `App\Generation\Kit` is what every player in one game begins holding, and the uploaded half of the
| units stage. `KitGenerator` is the drawn half and is tested next door.
|
| Two things this file exists to pin:
|
| - **the round trip.** A kit is meant to survive being downloaded, edited in a text editor and
|   uploaded back, seed included — that is the whole reason the seed lives inside the document rather
|   than only on the row. A round trip that quietly dropped it would look like nothing at all.
| - **every refusal, with its field.** `GenerationFailed` carries the form field the message belongs
|   on, and `Gamemaster\GenerationController` reads it. A message asserted without the field is a
|   message nobody would see in the right place, so both halves are asserted together — the same
|   pairing `HomeTemplateTest` makes.
|
*/

/**
 * A document describing a whole, valid kit, with one thing changed.
 *
 * Built by drawing one rather than by writing a literal, so the fixture cannot drift away from what
 * a kit actually is — a literal here would need updating every time the catalogue gains a kind, and
 * would silently stop describing every kind a game opens with.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
 * @return array<string, mixed>
 */
function kitDocument(?callable $mutate = null): array
{
    $document = (new KitGenerator(new StartingUnits))->generate(4242)->toArray();

    return $mutate === null ? $document : $mutate($document);
}

test('a kit survives being written out and read back', function () {
    $kit = (new KitGenerator(new StartingUnits))->generate(4242);

    $read = Kit::fromJson((string) json_encode($kit->toArray()), 'lean-start.json');

    /* The seed rides in the document, which is the point of putting it there. */
    expect($read->seed)->toBe(4242);
    expect($read->file)->toBe('lean-start.json');

    /* And the holdings are identical, entity by entity, in order. */
    expect($read->toArray()['entities'])->toBe($kit->toArray()['entities']);
});

test('a kit read back from storage keeps everything the document held', function () {
    $kit = (new KitGenerator(new StartingUnits))->generate(7);

    $stored = Kit::fromArray($kit->toArray());

    expect($stored->seed)->toBe(7);
    expect($stored->toArray())->toBe($kit->toArray());
});

test('a document with no seed is a kit somebody wrote rather than drew', function () {
    $read = Kit::fromJson((string) json_encode(kitDocument(function (array $document): array {
        unset($document['seed']);

        return $document;
    })), 'handwritten.json');

    expect($read->seed)->toBeNull();
});

test('the seed bounds agree with the ones a game is drawn from', function () {
    /*
     * `Kit` restates them rather than importing `Game`, because nothing in `app/Generation` may reach
     * for a model — the same trade `HomeTemplate::MAXIMUM_VALUE` makes with the planets table. This
     * is what keeps the restatement honest, so the day one moves it is a failing test rather than a
     * seed that truncates on its way into an unsigned column.
     */
    expect(Kit::MINIMUM_SEED)->toBe(Game::SEED_MIN);
    expect(Kit::MAXIMUM_SEED)->toBe(Game::SEED_MAX);
});

test('a file that is not json is refused, on the kit field', function () {
    expect(fn () => Kit::fromJson('not json at all', 'notes.txt'))
        ->toThrow(function (GenerationFailed $failure) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toContain('not readable as JSON');
        });
});

test('a kit that says nothing about one of the kinds a game opens with is refused', function () {
    /*
     * The strict rule, and the one somebody will be tempted to relax into "a missing kind starts
     * empty". A kit is the whole opening position: launching everybody with no ship at all is not
     * something anybody meant to ask for by leaving a key out.
     */
    $document = kitDocument(fn (array $document): array => [
        ...$document,
        'entities' => [$document['entities'][0]],
    ]);

    expect(fn () => Kit::fromJson((string) json_encode($document), 'kit.json'))
        ->toThrow(function (GenerationFailed $failure) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toBe('This kit says nothing about the Ship.');
        });
});

test('a kit describing a kind a player builds rather than begins with is refused', function () {
    $document = kitDocument(function (array $document): array {
        $document['entities'][] = [
            'type' => EntityType::OrbitalColony->value,
            'holdings' => [[
                'type' => UnitType::Food->value,
                'inventory' => Inventory::Operational->value,
                'technology_level' => 0,
                'quantity' => 10,
            ]],
        ];

        return $document;
    });

    expect(fn () => Kit::fromJson((string) json_encode($document), 'kit.json'))
        ->toThrow(function (GenerationFailed $failure) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toContain('Orbital Colony is something a player builds');
        });
});

test('the catalogue own rules are enforced through the document, naming the holding', function (
    array $holding,
    string $expected,
) {
    $document = kitDocument(function (array $document) use ($holding): array {
        $document['entities'][0]['holdings'][0] = $holding;

        return $document;
    });

    expect(fn () => Kit::fromJson((string) json_encode($document), 'kit.json'))
        ->toThrow(function (GenerationFailed $failure) use ($expected) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toContain($expected);
        });
})->with([
    /*
     * These three refusals live in `UnitHolding`'s constructor, which throws an
     * `InvalidArgumentException` because it is also how the catalogue's own kits are written. An
     * uploaded document is exactly the case its docblock says never reaches it, so `Kit` catches and
     * rethrows — and what this pins is that the sentence survives the rethrow with the entity and the
     * position prefixed onto it.
     */
    'an inventory the kind may not sit in' => [[
        'type' => UnitType::Mine->value,
        'inventory' => Inventory::Components->value,
        'technology_level' => 10,
        'quantity' => 4,
    ], 'Mine cannot be assigned to Components'],
    'a quantity of zero' => [[
        'type' => UnitType::Food->value,
        'inventory' => Inventory::Operational->value,
        'technology_level' => 0,
        'quantity' => 0,
    ], 'needs a quantity of at least one'],
    'a technology level on a kind that has none' => [[
        'type' => UnitType::Food->value,
        'inventory' => Inventory::Operational->value,
        'technology_level' => 5,
        'quantity' => 10,
    ], 'Food has no technology level'],
    'a technology level out of range' => [[
        'type' => UnitType::Mine->value,
        'inventory' => Inventory::Operational->value,
        'technology_level' => 44,
        'quantity' => 4,
    ], 'technology level from 1 to 10'],
]);

test('two holdings that would collide in the units unique key are refused', function () {
    /*
     * `(entity_id, type, inventory, technology_level)` is unique in that table, so a document saying
     * the same thing twice is one the stage could not insert. A `UnitHolding` knows its own three
     * values and nothing about its neighbours, which is why the check lives on `KitEntity`.
     */
    $document = kitDocument(function (array $document): array {
        $document['entities'][0]['holdings'][] = $document['entities'][0]['holdings'][0];

        return $document;
    });

    expect(fn () => Kit::fromJson((string) json_encode($document), 'kit.json'))
        ->toThrow(function (GenerationFailed $failure) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toContain('twice');
        });
});

test('a seed outside the range a column can hold is refused', function () {
    $document = kitDocument(fn (array $document): array => [...$document, 'seed' => Kit::MAXIMUM_SEED + 1]);

    expect(fn () => Kit::fromJson((string) json_encode($document), 'kit.json'))
        ->toThrow(function (GenerationFailed $failure) {
            expect($failure->field)->toBe('kit');
            expect($failure->getMessage())->toContain('whole number between');
        });
});

test('a kind the kit says nothing about answers with an empty list rather than a guess', function () {
    $kit = (new KitGenerator(new StartingUnits))->generate(4242);

    expect($kit->for(EntityType::OrbitalColony))->toBe([]);
    expect($kit->for(EntityType::OpenAirColony))->not->toBeEmpty();
});
