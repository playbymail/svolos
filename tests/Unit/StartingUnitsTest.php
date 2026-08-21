<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Generation\StartingUnits;
use App\Generation\UnitHolding;

/*
|--------------------------------------------------------------------------
| What every player is handed on turn one
|--------------------------------------------------------------------------
|
| Unlike every other file in this directory, the thing under test **draws nothing**: there is no seed
| and no randomizer, because every player gets the same kit and that is a fairness rule. So these tests
| are not about a distribution. They pin three things instead: that the kit is the same every time it
| is asked for, that no holding sits somewhere its kind is not allowed to, and that the ship's engines
| are in the hold rather than installed — which is the opening fiction stated as data, and the one
| thing here somebody could "fix" without realising they had rewritten the premise of the game.
|
*/

test('every player is handed exactly the same kit, however often it is asked for', function () {
    $starting = new StartingUnits;

    /*
     * Compared as arrays of the three fields rather than as objects, so this fails on a value having
     * changed rather than on identity — two `UnitHolding`s with the same contents are the same kit
     * whether or not they are the same instance.
     */
    $flatten = fn (array $holdings): array => array_map(
        fn (UnitHolding $holding): array => [
            $holding->type->value, $holding->inventory->value, $holding->quantity,
        ],
        $holdings,
    );

    foreach (EntityType::cases() as $type) {
        expect($flatten($starting->for($type)))->toBe($flatten((new StartingUnits)->for($type)));
    }
});

test('no holding sits in an inventory its kind does not allow', function () {
    $starting = new StartingUnits;

    foreach (EntityType::cases() as $type) {
        foreach ($starting->for($type) as $holding) {
            expect($holding->type->allows($holding->inventory))->toBeTrue();
        }
    }
});

test('a holding refuses to exist in an inventory its kind does not allow', function () {
    /*
     * The rule is enforced in the constructor rather than checked by a caller, so an illegal kit fails
     * the moment the file is loaded. Food is never components: components means the frame and the
     * systems of the entity, and a crate of food is neither.
     */
    expect(fn () => new UnitHolding(UnitType::Food, Inventory::Components, 1))
        ->toThrow(InvalidArgumentException::class, 'cannot be assigned to Components');
});

test('a holding refuses to be a quantity of nothing', function () {
    /* "None of this" is the absence of a row, not a row saying zero — two ways to say one thing. */
    expect(fn () => new UnitHolding(UnitType::Fuel, Inventory::Cargo, 0))
        ->toThrow(InvalidArgumentException::class, 'at least one');
});

test('the ship carries its engines in the hold and none installed', function () {
    $ship = (new StartingUnits)->ship();

    $engines = array_values(array_filter(
        $ship,
        fn (UnitHolding $holding): bool => $holding->type === UnitType::Engine,
    ));

    /*
     * The whole opening: "The main engines are gone. Burned out sometime during the voyage." A ship's
     * ability to move will be read off its **components**, so engines in cargo are spares in
     * crates and this ship cannot leave. Moving them to `Components` would undo the premise the
     * game opens on without touching a single line of rules code, which is why it is asserted here.
     */
    expect($engines)->toHaveCount(1);
    expect($engines[0]->inventory)->toBe(Inventory::Cargo);
});

test('a colony is given mines and factories it is already working', function () {
    $operational = array_values(array_filter(
        (new StartingUnits)->colony(),
        fn (UnitHolding $holding): bool => $holding->inventory === Inventory::Operational
            && in_array($holding->type, [UnitType::Mine, UnitType::Factory], true),
    ));

    /* The abandoned installations: "The mines remain. The factories remain." */
    expect($operational)->toHaveCount(2);

    foreach ($operational as $holding) {
        expect($holding->quantity)->toBeGreaterThan(0);
    }
});

test('a holding weighs and takes up its kind times its quantity', function () {
    $holding = new UnitHolding(UnitType::LightStructural, Inventory::Components, 20);

    expect($holding->mass())->toBe(UnitType::LightStructural->mass() * 20);
    expect($holding->volume())->toBe(UnitType::LightStructural->assembledVolume() * 20);
});

test('every kind of entity has a kit', function (EntityType $type) {
    expect((new StartingUnits)->for($type))->not->toBeEmpty();
})->with(EntityType::cases());

test('the kinds of entity a player begins with are the ones with kits', function () {
    /*
     * `entityTypes()` is what the action loops, and `for()` is what it asks of each. A kind added to
     * one and not the other would place an entity holding nothing, or describe a kit nobody is given.
     */
    expect((new StartingUnits)->entityTypes())->toBe([EntityType::Colony, EntityType::Ship]);
});
