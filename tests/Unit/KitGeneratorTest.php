<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Generation\Kit;
use App\Generation\KitGenerator;
use App\Generation\StartingUnits;
use App\Generation\UnitHolding;

/*
|--------------------------------------------------------------------------
| Drawing a kit
|--------------------------------------------------------------------------
|
| `KitGenerator` draws the kit one game opens with, for a gamemaster who has no document to upload.
|
| The rule it exists inside is worth restating, because a reader of `StartingUnits` will arrive here
| believing this class should not exist: **the units stage's fairness rule is about players, not
| about games.** A kit is drawn once and every player in that game gets it unchanged. What the seed
| varies is what *this game's* opening is, exactly as `HomeTemplateGenerator` varies the home system
| every player shares.
|
| Two things are pinned hard:
|
| - **the shape is not drawn.** Which kinds, which inventories, which technology levels all come from
|   `StartingUnits` untouched, because three facts of the setting ride on them — the ship's engines
|   are crated, the colony is a city built for an armada, and only the hull is a component.
| - **the draw schedule.** One roll per holding, in the baseline's own order. Pinned as literals,
|   because comparing two runs of the same code cannot catch a shifted stream: it agrees with itself
|   perfectly while every stored kit quietly changes.
|
*/

/**
 * Draw a kit with the real baseline behind it.
 */
function drawKit(int $seed): Kit
{
    return (new KitGenerator(new StartingUnits))->generate($seed);
}

test('the same seed draws the same kit, and a different one draws a different kit', function () {
    expect(drawKit(4242)->toArray())->toBe(drawKit(4242)->toArray());
    expect(drawKit(4242)->toArray())->not->toBe(drawKit(99)->toArray());
});

test('a drawn kit remembers the seed it came from and no file', function () {
    $kit = drawKit(4242);

    expect($kit->seed)->toBe(4242);
    expect($kit->file)->toBeNull();
});

test('the draw schedule is pinned, so a reordered manifest cannot pass unnoticed', function () {
    /*
     * Literals, not a comparison against another run. One roll per holding in the baseline's order,
     * so inserting a holding — or reordering `StartingUnits::openAirColony()` — shifts every draw
     * after it and changes every kit any stored seed produces. That is a decision somebody should
     * make deliberately, which is what failing here forces.
     */
    $colony = drawKit(4242)->for(EntityType::OpenAirColony);

    expect(array_map(fn (UnitHolding $holding): int => $holding->quantity, $colony))
        ->toBe([17, 4, 2, 440, 930, 540, 428, 137, 268]);
});

test('only the quantities are drawn: every kind, inventory and level is the baseline unchanged', function (
    EntityType $type,
) {
    $baseline = (new StartingUnits)->for($type);
    $drawn = drawKit(4242)->for($type);

    expect($drawn)->toHaveCount(count($baseline));

    foreach ($baseline as $position => $expected) {
        expect($drawn[$position]->type)->toBe($expected->type);
        expect($drawn[$position]->inventory)->toBe($expected->inventory);
        expect($drawn[$position]->technologyLevel)->toBe($expected->technologyLevel);
    }
})->with([
    'the colony' => [EntityType::OpenAirColony],
    'the ship' => [EntityType::Ship],
]);

test('the ship is still carrying its engines rather than running on them, whatever the seed', function (int $seed) {
    /*
     * "The main engines are gone. Burned out sometime during the voyage." A generator that drew the
     * inventory as well as the quantity would undo the premise the whole game opens on without
     * touching a line of rules code — see `StartingUnits`, which asserts the same thing about the
     * catalogue's own kit.
     */
    $ship = drawKit($seed)->for(EntityType::Ship);

    $engines = array_values(array_filter(
        $ship,
        fn (UnitHolding $holding): bool => $holding->type === UnitType::Engine,
    ));

    expect($engines)->toHaveCount(1);
    expect($engines[0]->inventory)->toBe(Inventory::Cargo);

    $components = array_map(
        fn (UnitHolding $holding): UnitType => $holding->type,
        array_filter($ship, fn (UnitHolding $holding): bool => $holding->inventory === Inventory::Components),
    );

    expect(array_values($components))->toBe([UnitType::LightStructure]);
})->with([[1], [4242], [99_999], [4_294_967_295]]);

test('every drawn quantity stays inside the band, and never falls out of existence', function (int $seed) {
    foreach (EntityType::startingKinds() as $type) {
        $baseline = (new StartingUnits)->for($type);
        $drawn = drawKit($seed)->for($type);

        foreach ($baseline as $position => $expected) {
            $quantity = $drawn[$position]->quantity;

            /*
             * The band is a percentage, and the rounding is to nearest — see `KitGenerator::quantity()`
             * for why truncating is a bug rather than a preference. The bounds here are computed the
             * same way so a change to `VARIATION` moves both together.
             */
            $lowest = max(
                KitGenerator::MINIMUM_QUANTITY,
                intdiv($expected->quantity * (100 - KitGenerator::VARIATION) + 50, 100),
            );
            $highest = intdiv($expected->quantity * (100 + KitGenerator::VARIATION) + 50, 100);

            expect($quantity)->toBeGreaterThanOrEqual($lowest);
            expect($quantity)->toBeLessThanOrEqual($highest);
            expect($quantity)->toBeGreaterThanOrEqual(KitGenerator::MINIMUM_QUANTITY);
        }
    }
})->with([[1], [7], [4242], [99_999], [4_294_967_295]]);

test('a quantity small enough for the band to be meaningless does not move at all', function (int $seed) {
    /*
     * The bug this pins is the reason `quantity()` rounds rather than truncates. Fifteen percent off
     * a baseline of two is 1.7, and truncation makes that **one** — a fifty percent cut out of a
     * fifteen percent band, because the whole shortfall lands on the one unit there is. It halved the
     * ship's crated engines, which is a number the opening fiction turns on.
     */
    $ship = drawKit($seed)->for(EntityType::Ship);

    $engines = array_values(array_filter(
        $ship,
        fn (UnitHolding $holding): bool => $holding->type === UnitType::Engine,
    ));

    expect($engines[0]->quantity)->toBe(2);
})->with([[1], [7], [4242], [99_999], [4_294_967_295]]);

test('different seeds really do produce different games', function () {
    /*
     * The feature, stated as a measurement rather than as an example: if a wide sweep of seeds all
     * produced the same kit, every test above would still pass and the stage would have gained
     * nothing at all.
     */
    $kits = array_map(
        fn (int $seed): string => (string) json_encode(drawKit($seed)->toArray()['entities']),
        range(1, 50),
    );

    expect(count(array_unique($kits)))->toBeGreaterThan(45);
});
