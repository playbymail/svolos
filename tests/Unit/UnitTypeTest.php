<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;

/*
|--------------------------------------------------------------------------
| The catalogue is code, so a test can sweep it
|--------------------------------------------------------------------------
|
| The whole reason the kinds of unit are an enum rather than a table: every case can be walked and
| held to the same standard, and a case added without deciding what it weighs or where it may sit
| fails here rather than shipping as a half-defined kind. Everything below is `->with(...cases())` for
| that reason — none of these tests names a kind.
|
*/

test('every kind says what it is called, what it weighs and how much room it takes', function (UnitType $type) {
    expect($type->label())->not->toBeEmpty();
    expect($type->mass())->toBeGreaterThan(0);
    expect($type->volume())->toBeGreaterThan(0);
})->with(UnitType::cases());

test('every kind may sit somewhere, and never in an inventory twice', function (UnitType $type) {
    $inventories = $type->inventories();

    expect($inventories)->not->toBeEmpty();
    expect($inventories)->toBe(array_values(array_unique($inventories, SORT_REGULAR)));
})->with(UnitType::cases());

test('allows agrees with inventories, in both directions', function (UnitType $type) {
    /*
     * `allows()` is the question every caller asks and `inventories()` is the answer it reads, so the
     * two disagreeing would be a rule enforced one way and reported another.
     */
    foreach (Inventory::cases() as $inventory) {
        expect($type->allows($inventory))->toBe(in_array($inventory, $type->inventories(), true));
    }
})->with(UnitType::cases());

test('components is the closed inventory, and holds only what an entity is built from', function () {
    /*
     * The one rule in the catalogue that makes the three inventories mean anything. Components is
     * the frame and the systems of the entity itself, so only the two kinds an entity is *built from*
     * may be assigned to it — and the day a third kind belongs there it should be a decision somebody
     * makes against this sentence rather than a `default` arm quietly including it.
     */
    $structural = array_values(array_filter(
        UnitType::cases(),
        fn (UnitType $type): bool => $type->allows(Inventory::Components),
    ));

    expect($structural)->toBe([UnitType::Structure, UnitType::Engine]);
});

test('a mine is never part of what an entity is made of', function () {
    /* A colony's mine is a thing it operates, not a thing it is. The same for its factories. */
    expect(UnitType::Mine->allows(Inventory::Components))->toBeFalse();
    expect(UnitType::Factory->allows(Inventory::Components))->toBeFalse();
    expect(UnitType::Mine->allows(Inventory::Operational))->toBeTrue();
    expect(UnitType::Factory->allows(Inventory::Operational))->toBeTrue();
});

test('every inventory says what it is called', function (Inventory $inventory) {
    expect($inventory->label())->not->toBeEmpty();
})->with(Inventory::cases());

test('only a ship can ever move', function () {
    /*
     * A fact about the kind rather than about the row: whether a *particular* ship can move depends on
     * its fuel and its installed engines, and nothing answers that yet because no order asks it.
     */
    expect(EntityType::Ship->isMobile())->toBeTrue();
    expect(EntityType::Colony->isMobile())->toBeFalse();
});

test('every kind of entity says what it is called', function (EntityType $type) {
    expect($type->label())->not->toBeEmpty();
})->with(EntityType::cases());
