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
        (new StartingUnits)->openAirColony(),
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
    $holding = new UnitHolding(UnitType::LightStructure, Inventory::Components, 20, 10);

    expect($holding->mass())->toBe(UnitType::LightStructure->mass(10) * 20);
    expect($holding->volume(EntityType::OpenAirColony))
        ->toBe(UnitType::LightStructure->assembledVolume(10, EntityType::OpenAirColony) * 20);

    /* A crated holding pays the rounding: twenty STRC-5 crate to a whole 50 VU, nineteen to 47.5
     * and therefore occupy 48. */
    $crated = new UnitHolding(UnitType::Structure, Inventory::Cargo, 19, 5);

    expect($crated->volume(EntityType::Ship))->toBe(48 * UnitType::SCALE);
});

test('every kind of entity that starts a game has a kit, and the others have none', function () {
    /*
     * Two of the four kinds start a game. An enclosed colony and an orbital colony are things a
     * player builds, so `for()` answers them with an empty kit rather than a guess — and this is what
     * says that emptiness is deliberate rather than an unfinished arm.
     */
    $units = new StartingUnits;

    foreach ($units->entityTypes() as $type) {
        expect($units->for($type))->not->toBeEmpty();
    }

    expect($units->for(EntityType::EnclosedColony))->toBe([]);
    expect($units->for(EntityType::OrbitalColony))->toBe([]);
});

test('the kinds of entity a player begins with are the ones with kits', function () {
    /*
     * `entityTypes()` is what the action loops, and `for()` is what it asks of each. A kind added to
     * one and not the other would place an entity holding nothing, or describe a kit nobody is given.
     */
    expect((new StartingUnits)->entityTypes())->toBe([EntityType::OpenAirColony, EntityType::Ship]);
});

test('a holding refuses a technology level its kind cannot have', function () {
    /*
     * The same argument as the inventory rule two tests up: the kits are constants, so a holding
     * contradicting the catalogue is a mistake in the source and should fail when the file loads.
     *
     * Both directions, because both are wrong in the same way — a row that says something the
     * catalogue does not. A raw commodity at level 3 would print as `FOOD-3`, and a levelled kind at
     * 0 would print as `STRL-0`, which is not a thing anybody can build.
     */
    expect(fn () => new UnitHolding(UnitType::Food, Inventory::Cargo, 10, 3))
        ->toThrow(InvalidArgumentException::class, 'Food has no technology level.');

    expect(fn () => new UnitHolding(UnitType::LightStructure, Inventory::Cargo, 10, 0))
        ->toThrow(InvalidArgumentException::class, 'built at a technology level from 1 to 10');

    expect(fn () => new UnitHolding(UnitType::LightStructure, Inventory::Cargo, 10, 11))
        ->toThrow(InvalidArgumentException::class, 'built at a technology level from 1 to 10');
});

test('every holding in every kit agrees with the catalogue about levels', function () {
    /*
     * The kits are the only thing writing units today, so this is the sweep that keeps them honest
     * without naming a kind: whatever `StartingUnits` holds, its level is one the catalogue allows.
     *
     * Driven off `entityTypes()` rather than `EntityType::cases()`. Two of the four kinds have no
     * kit, so a case-driven sweep spent half its runs asserting nothing at all — which PHPUnit
     * reported as risky, and which was the only reason anybody noticed that the assertion inside was
     * comparing a value to itself.
     */
    $units = new StartingUnits;
    $checked = 0;

    foreach ($units->entityTypes() as $type) {
        foreach ($units->for($type) as $holding) {
            /* The catalogue's own rule, asked of the value the kit actually chose. */
            expect(fn () => $holding->type->assertTechnologyLevel($holding->technologyLevel))
                ->not->toThrow(InvalidArgumentException::class);

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

test('the kit that crossed the stars is all of one era', function () {
    /*
     * "You crossed the stars aboard technology your people once took for granted." Everything the
     * expedition left and everything still aboard is the best there was, so every kind in the kits
     * that has a level is at 10. The day a kit deliberately holds something older, this is the test
     * that asks whether that was meant.
     */
    $units = new StartingUnits;
    $levelled = 0;

    foreach ($units->entityTypes() as $type) {
        foreach ($units->for($type) as $holding) {
            if ($holding->type->hasTechnologyLevel()) {
                expect($holding->technologyLevel)->toBe(UnitType::MAXIMUM_TECHNOLOGY_LEVEL);

                $levelled++;
            }
        }
    }

    /* If nothing in the kits has a level any more, this test has stopped meaning anything. */
    expect($levelled)->toBeGreaterThan(0);
});
