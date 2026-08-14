<?php

use App\Enums\AssetAssignment;
use App\Enums\AssetType;
use App\Enums\EntityType;

/*
|--------------------------------------------------------------------------
| The catalogue is code, so a test can sweep it
|--------------------------------------------------------------------------
|
| The whole reason the kinds of asset are an enum rather than a table: every case can be walked and
| held to the same standard, and a case added without deciding what it weighs or where it may sit
| fails here rather than shipping as a half-defined kind. Everything below is `->with(...cases())` for
| that reason — none of these tests names a kind.
|
*/

test('every kind says what it is called, what it weighs and how much room it takes', function (AssetType $type) {
    expect($type->label())->not->toBeEmpty();
    expect($type->mass())->toBeGreaterThan(0);
    expect($type->volume())->toBeGreaterThan(0);
})->with(AssetType::cases());

test('every kind may sit somewhere, and never in an assignment twice', function (AssetType $type) {
    $assignments = $type->assignments();

    expect($assignments)->not->toBeEmpty();
    expect($assignments)->toBe(array_values(array_unique($assignments, SORT_REGULAR)));
})->with(AssetType::cases());

test('allows agrees with assignments, in both directions', function (AssetType $type) {
    /*
     * `allows()` is the question every caller asks and `assignments()` is the answer it reads, so the
     * two disagreeing would be a rule enforced one way and reported another.
     */
    foreach (AssetAssignment::cases() as $assignment) {
        expect($type->allows($assignment))->toBe(in_array($assignment, $type->assignments(), true));
    }
})->with(AssetType::cases());

test('infrastructure is the closed assignment, and holds only what an entity is built from', function () {
    /*
     * The one rule in the catalogue that makes the three assignments mean anything. Infrastructure is
     * the frame and the systems of the entity itself, so only the two kinds an entity is *built from*
     * may be assigned to it — and the day a third kind belongs there it should be a decision somebody
     * makes against this sentence rather than a `default` arm quietly including it.
     */
    $structural = array_values(array_filter(
        AssetType::cases(),
        fn (AssetType $type): bool => $type->allows(AssetAssignment::Infrastructure),
    ));

    expect($structural)->toBe([AssetType::Structure, AssetType::Engine]);
});

test('a mine is never part of what an entity is made of', function () {
    /* A colony's mine is a thing it operates, not a thing it is. The same for its factories. */
    expect(AssetType::Mine->allows(AssetAssignment::Infrastructure))->toBeFalse();
    expect(AssetType::Factory->allows(AssetAssignment::Infrastructure))->toBeFalse();
    expect(AssetType::Mine->allows(AssetAssignment::Operational))->toBeTrue();
    expect(AssetType::Factory->allows(AssetAssignment::Operational))->toBeTrue();
});

test('every assignment says what it is called', function (AssetAssignment $assignment) {
    expect($assignment->label())->not->toBeEmpty();
})->with(AssetAssignment::cases());

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
