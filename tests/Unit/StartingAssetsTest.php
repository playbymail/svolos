<?php

use App\Enums\AssetAssignment;
use App\Enums\AssetType;
use App\Enums\EntityType;
use App\Generation\AssetHolding;
use App\Generation\StartingAssets;

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
    $starting = new StartingAssets;

    /*
     * Compared as arrays of the three fields rather than as objects, so this fails on a value having
     * changed rather than on identity — two `AssetHolding`s with the same contents are the same kit
     * whether or not they are the same instance.
     */
    $flatten = fn (array $holdings): array => array_map(
        fn (AssetHolding $holding): array => [
            $holding->type->value, $holding->assignment->value, $holding->quantity,
        ],
        $holdings,
    );

    foreach (EntityType::cases() as $type) {
        expect($flatten($starting->for($type)))->toBe($flatten((new StartingAssets)->for($type)));
    }
});

test('no holding sits in an assignment its kind does not allow', function () {
    $starting = new StartingAssets;

    foreach (EntityType::cases() as $type) {
        foreach ($starting->for($type) as $holding) {
            expect($holding->type->allows($holding->assignment))->toBeTrue();
        }
    }
});

test('a holding refuses to exist in an assignment its kind does not allow', function () {
    /*
     * The rule is enforced in the constructor rather than checked by a caller, so an illegal kit fails
     * the moment the file is loaded. Food is never infrastructure: infrastructure is the frame and the
     * systems of the entity, and a crate of food is neither.
     */
    expect(fn () => new AssetHolding(AssetType::Food, AssetAssignment::Infrastructure, 1))
        ->toThrow(InvalidArgumentException::class, 'cannot be assigned to Infrastructure');
});

test('a holding refuses to be a quantity of nothing', function () {
    /* "None of this" is the absence of a row, not a row saying zero — two ways to say one thing. */
    expect(fn () => new AssetHolding(AssetType::Fuel, AssetAssignment::Cargo, 0))
        ->toThrow(InvalidArgumentException::class, 'at least one');
});

test('the ship carries its engines in the hold and none installed', function () {
    $ship = (new StartingAssets)->ship();

    $engines = array_values(array_filter(
        $ship,
        fn (AssetHolding $holding): bool => $holding->type === AssetType::Engine,
    ));

    /*
     * The whole opening: "The main engines are gone. Burned out sometime during the voyage." A ship's
     * ability to move will be read off its **infrastructure**, so engines in cargo are spares in
     * crates and this ship cannot leave. Moving them to `Infrastructure` would undo the premise the
     * game opens on without touching a single line of rules code, which is why it is asserted here.
     */
    expect($engines)->toHaveCount(1);
    expect($engines[0]->assignment)->toBe(AssetAssignment::Cargo);
});

test('a colony is given mines and factories it is already working', function () {
    $operational = array_values(array_filter(
        (new StartingAssets)->colony(),
        fn (AssetHolding $holding): bool => $holding->assignment === AssetAssignment::Operational
            && in_array($holding->type, [AssetType::Mine, AssetType::Factory], true),
    ));

    /* The abandoned installations: "The mines remain. The factories remain." */
    expect($operational)->toHaveCount(2);

    foreach ($operational as $holding) {
        expect($holding->quantity)->toBeGreaterThan(0);
    }
});

test('a holding weighs and takes up its kind times its quantity', function () {
    $holding = new AssetHolding(AssetType::Structure, AssetAssignment::Infrastructure, 20);

    expect($holding->mass())->toBe(AssetType::Structure->mass() * 20);
    expect($holding->volume())->toBe(AssetType::Structure->volume() * 20);
});

test('every kind of entity has a kit', function (EntityType $type) {
    expect((new StartingAssets)->for($type))->not->toBeEmpty();
})->with(EntityType::cases());

test('the kinds of entity a player begins with are the ones with kits', function () {
    /*
     * `entityTypes()` is what the action loops, and `for()` is what it asks of each. A kind added to
     * one and not the other would place an entity holding nothing, or describe a kit nobody is given.
     */
    expect((new StartingAssets)->entityTypes())->toBe([EntityType::Colony, EntityType::Ship]);
});
