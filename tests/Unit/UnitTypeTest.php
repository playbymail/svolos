<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitCategory;
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

/**
 * A technology level this kind can actually be built at.
 *
 * The sweeps below walk every case, and a measure now refuses a level its kind cannot have — so each
 * one needs a level that fits. The top of the range for a kind that has one, and none for a kind that
 * does not.
 */
function levelFor(UnitType $type): int
{
    return $type->hasTechnologyLevel()
        ? UnitType::MAXIMUM_TECHNOLOGY_LEVEL
        : UnitType::NO_TECHNOLOGY_LEVEL;
}

test('every kind says what it is called, what it weighs and how much room it takes', function (UnitType $type) {
    $level = levelFor($type);

    expect($type->label())->not->toBeEmpty();
    expect($type->mass($level))->toBeGreaterThan(0);
    expect($type->assembledVolume($level))->toBeGreaterThan(0);
    expect($type->disassembledVolume($level))->toBeGreaterThan(0);
})->with(UnitType::cases());

test('a measure refuses a technology level its kind cannot be built at', function (UnitType $type) {
    /*
     * The guard exists because a measure may *depend* on the level: `LifeSupport->mass(0)` would
     * return zero and flow into a capacity calculation as a unit that weighs nothing, which is a
     * wrong answer rather than an error. Asserted for every kind so the two directions stay
     * symmetrical — a levelled kind refuses 0, a raw one refuses anything else.
     */
    $wrong = $type->hasTechnologyLevel() ? UnitType::NO_TECHNOLOGY_LEVEL : 5;

    expect(fn () => $type->mass($wrong))->toThrow(InvalidArgumentException::class);
    expect(fn () => $type->assembledVolume($wrong))->toThrow(InvalidArgumentException::class);
    expect(fn () => $type->disassembledVolume($wrong))->toThrow(InvalidArgumentException::class);
})->with(UnitType::cases());

test('crating a kind never makes it take more room', function (UnitType $type) {
    /*
     * The point of a disassembled volume is that it is smaller. Equal is allowed — raw ore does not
     * pack down — but larger would mean stowing something made it bulkier, which is not a state the
     * rules have any way to mean.
     */
    $level = levelFor($type);

    expect($type->disassembledVolume($level))->toBeLessThanOrEqual($type->assembledVolume($level));
})->with(UnitType::cases());

test('the inventory decides which volume a kind is measured at', function (UnitType $type) {
    /*
     * `volumeIn()` is the measure every capacity question asks for, and the decision behind it
     * belongs to `Inventory` rather than to the kind. Asserted for every pairing so that a fourth
     * inventory cannot be added without answering the question.
     */
    $level = levelFor($type);

    foreach (Inventory::cases() as $inventory) {
        expect($type->volumeIn($inventory, $level))->toBe(
            $inventory->usesDisassembledVolume()
                ? $type->disassembledVolume($level)
                : $type->assembledVolume($level),
        );
    }
})->with(UnitType::cases());

test('cargo is the only inventory that crates what it holds', function () {
    expect(Inventory::Cargo->usesDisassembledVolume())->toBeTrue();
    expect(Inventory::Components->usesDisassembledVolume())->toBeFalse();
    expect(Inventory::Operational->usesDisassembledVolume())->toBeFalse();
});

test('the structural kinds carry the measures they were given', function () {
    /*
     * The one test here that names kinds, because these two are the only ones whose numbers are
     * settled rather than placeholders. Written as the decimals they are read as, times the scale,
     * so that a change to `SCALE` does not quietly change what this asserts.
     */
    expect(UnitType::Structure->mass(10))->toBe((int) (0.5 * UnitType::SCALE));
    expect(UnitType::Structure->assembledVolume(10))->toBe((int) (1.0 * UnitType::SCALE));
    expect(UnitType::Structure->disassembledVolume(10))->toBe((int) (0.5 * UnitType::SCALE));

    expect(UnitType::LightStructure->mass(10))->toBe((int) (0.05 * UnitType::SCALE));
    expect(UnitType::LightStructure->assembledVolume(10))->toBe((int) (0.1 * UnitType::SCALE));
    expect(UnitType::LightStructure->disassembledVolume(10))->toBe((int) (0.05 * UnitType::SCALE));

    /* Flat kinds are flat: the level is accepted and changes nothing. */
    expect(UnitType::Structure->mass(1))->toBe(UnitType::Structure->mass(10));
});

test('a measure is printed as the decimal it stands for', function () {
    /*
     * The one place hundredths become the number a report prints. Two decimal places always, so a
     * column of measures lines up.
     */
    expect(UnitType::format(UnitType::Structure->mass(10)))->toBe('0.50');
    expect(UnitType::format(UnitType::LightStructure->mass(10)))->toBe('0.05');
    expect(UnitType::format(UnitType::LightStructure->assembledVolume(10) * 300))->toBe('30.00');
});

test('a report code is unique, and the kinds still without one are known', function () {
    /*
     * Two kinds sharing a code would make an order ambiguous, which is the failure worth a test.
     *
     * The second half is the list of kinds that have no code yet. It is deliberately spelled out:
     * naming one is a decision, and this fails the moment somebody makes it, which is when the rest
     * of the catalogue's numbers want revisiting anyway.
     */
    $codes = array_filter(array_map(fn (UnitType $type): ?string => $type->abbreviation(), UnitType::cases()));

    expect($codes)->toBe(array_unique($codes));

    foreach ($codes as $code) {
        expect($code)->toBe(mb_strtoupper($code));
    }

    expect(UnitType::Structure->abbreviation())->toBe('STRC');
    expect(UnitType::LightStructure->abbreviation())->toBe('STRL');
    expect(UnitType::Food->abbreviation())->toBe('FOOD');
    expect(UnitType::Fuel->abbreviation())->toBe('FUEL');
    expect(UnitType::Metals->abbreviation())->toBe('METL');
    expect(UnitType::Minerals->abbreviation())->toBe('MNRL');

    $unnamed = array_values(array_map(
        fn (UnitType $type): string => $type->value,
        array_filter(UnitType::cases(), fn (UnitType $type): bool => $type->abbreviation() === null),
    ));

    expect($unnamed)->toBe(['engine', 'mine', 'factory', 'machinery', 'supplies']);
});

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
     * the frame and the systems of the entity itself, so only the kinds an entity is *built from*
     * may be assigned to it — and the day a third kind belongs there it should be a decision somebody
     * makes against this sentence rather than a `default` arm quietly including it.
     */
    $structural = array_values(array_filter(
        UnitType::cases(),
        fn (UnitType $type): bool => $type->allows(Inventory::Components),
    ));

    expect($structural)->toBe([
        UnitType::Structure,
        UnitType::LightStructure,
        UnitType::Engine,
        UnitType::LifeSupport,
    ]);
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

test('a kind either has a technology level or has none, and the split is written down', function () {
    /*
     * The list is the point. `hasTechnologyLevel()` is settled only for the two structural kinds;
     * the rest are answered by whether they read as manufactured or as raw, and this fails the
     * moment somebody decides one of them properly — which is when its measures and its report code
     * want deciding too.
     */
    $levelled = array_values(array_map(
        fn (UnitType $type): string => $type->value,
        array_filter(UnitType::cases(), fn (UnitType $type): bool => $type->hasTechnologyLevel()),
    ));

    expect($levelled)->toBe([
        'structure', 'light_structure', 'engine', 'life_support', 'mine', 'factory', 'machinery',
    ]);

    $raw = array_values(array_map(
        fn (UnitType $type): string => $type->value,
        array_filter(UnitType::cases(), fn (UnitType $type): bool => ! $type->hasTechnologyLevel()),
    ));

    expect($raw)->toBe(['fuel', 'food', 'consumer_goods', 'metals', 'minerals', 'supplies']);
});

test('a report names a levelled kind with its level, and a raw one without', function () {
    /*
     * `FOOD`, never `FOOD-0`. The engine knows which kinds have a level, so the zero never reaches a
     * reader — which is the whole reason the absent case can be a sentinel rather than a null.
     */
    expect(UnitType::LightStructure->reportName(10))->toBe('STRL-10');
    expect(UnitType::LightStructure->reportName(2))->toBe('STRL-2');
    expect(UnitType::Structure->reportName(7))->toBe('STRC-7');

    expect(UnitType::Food->reportName(UnitType::NO_TECHNOLOGY_LEVEL))->toBe('FOOD');
    expect(UnitType::Fuel->reportName(UnitType::NO_TECHNOLOGY_LEVEL))->toBe('FUEL');
});

test('a kind with no report code has no report name either', function () {
    /*
     * Nine kinds are still unnamed, and inventing a code so that a report has something to print
     * would make it hard to change later. Null says "not decided" where a placeholder would not.
     */
    expect(UnitType::Engine->abbreviation())->toBeNull();
    expect(UnitType::Engine->reportName(10))->toBeNull();
});

test('every kind either has a category or is one of the two still without', function () {
    /*
     * The category table settled thirteen categories and the codes for four of them. `Machinery` and
     * `Supplies` appear in none of it and read ambiguously from their names, so they answer null —
     * and this is the list that fails the moment either is decided, which is when its report code
     * and its measures want deciding too.
     */
    $uncategorised = array_values(array_map(
        fn (UnitType $type): string => $type->value,
        array_filter(UnitType::cases(), fn (UnitType $type): bool => $type->category() === null),
    ));

    expect($uncategorised)->toBe(['machinery', 'supplies']);
});

test('a category and the kinds in it agree with each other', function (UnitCategory $category) {
    /*
     * `UnitCategory::types()` reads `UnitType::category()` rather than keeping a second list, and
     * this is what holds that true in both directions: every kind the category claims names it back.
     *
     * Most categories are empty — nine of the thirteen have no kind in the catalogue yet — which is
     * itself worth asserting rather than skipping, since an empty category is a real state and not a
     * broken one.
     */
    foreach ($category->types() as $type) {
        expect($type->category())->toBe($category);
    }

    expect($category->label())->not->toBeEmpty();
    expect($category->description())->not->toBeEmpty();
})->with(UnitCategory::cases());

test('the categories that have kinds are the ones the table gave codes for', function () {
    /*
     * The four categories the catalogue can currently populate, and what is in each. This is the
     * inventory of what has actually been settled — everything else in `UnitCategory` is a name and
     * a definition waiting for its kinds.
     */
    expect(UnitCategory::Structural->types())->toBe([UnitType::Structure, UnitType::LightStructure]);
    expect(UnitCategory::Resource->types())->toBe([UnitType::Fuel, UnitType::Metals, UnitType::Minerals]);
    expect(UnitCategory::Commodity->types())->toBe([UnitType::Food, UnitType::ConsumerGoods]);
    expect(UnitCategory::Propulsion->types())->toBe([UnitType::Engine]);
    expect(UnitCategory::Infrastructure->types())->toBe([UnitType::Mine, UnitType::Factory]);

    expect(UnitCategory::Static->types())->toBe([UnitType::LifeSupport]);

    /* Eight categories still have no kind at all, which is a real state and not a broken one. */
    expect(UnitCategory::Weaponry->types())->toBe([]);
});

test('consumer goods carry the measures they were given', function () {
    /*
     * Denser than it is bulky: 0.6 MU in 0.3 VU. Consumer goods are the second Commodity beside
     * food, and like every commodity they have no technology level — a crate of them is a crate of
     * them.
     */
    expect(UnitType::ConsumerGoods->abbreviation())->toBe('CSGD');
    expect(UnitType::ConsumerGoods->category())->toBe(UnitCategory::Commodity);
    expect(UnitType::ConsumerGoods->hasTechnologyLevel())->toBeFalse();

    $none = UnitType::NO_TECHNOLOGY_LEVEL;

    expect(UnitType::ConsumerGoods->mass($none))->toBe((int) (0.6 * UnitType::SCALE));
    expect(UnitType::ConsumerGoods->assembledVolume($none))->toBe((int) (0.3 * UnitType::SCALE));
    expect(UnitType::ConsumerGoods->disassembledVolume($none))->toBe((int) (0.15 * UnitType::SCALE));

    expect(UnitType::ConsumerGoods->reportName($none))->toBe('CSGD');
});

test('a life support unit is measured by its technology level', function (int $level) {
    /*
     * The first kind whose measures are a *function* of the level rather than a constant: 8 × TL MU,
     * 8 × TL VU assembled, 4 × TL VU crated. A TL-10 unit is ten times a TL-1 one in every measure.
     *
     * Swept across the whole range rather than asserted at one level, because the arithmetic is the
     * content here — a transposed multiplier would still pass a single-level check at TL 1.
     */
    expect(UnitType::LifeSupport->mass($level))->toBe(8 * UnitType::SCALE * $level);
    expect(UnitType::LifeSupport->assembledVolume($level))->toBe(8 * UnitType::SCALE * $level);
    expect(UnitType::LifeSupport->disassembledVolume($level))->toBe(4 * UnitType::SCALE * $level);

    /* Crating one always halves it, at every level. */
    expect(UnitType::LifeSupport->disassembledVolume($level) * 2)
        ->toBe(UnitType::LifeSupport->assembledVolume($level));
})->with(range(UnitType::MINIMUM_TECHNOLOGY_LEVEL, UnitType::MAXIMUM_TECHNOLOGY_LEVEL));

test('life support is something an entity is built from', function () {
    /*
     * The glossary named it as a component before the kind existed: components are "the structure of
     * its hull, its engines, its life support, sensors and weapons". So it sits where the hull and
     * the engines sit, and never in operational.
     */
    expect(UnitType::LifeSupport->abbreviation())->toBe('LSU');
    expect(UnitType::LifeSupport->category())->toBe(UnitCategory::Static);
    expect(UnitType::LifeSupport->inventories())->toBe([Inventory::Components, Inventory::Cargo]);
    expect(UnitType::LifeSupport->allows(Inventory::Operational))->toBeFalse();

    expect(UnitType::LifeSupport->reportName(10))->toBe('LSU-10');
    expect(UnitType::LifeSupport->reportName(1))->toBe('LSU-1');
});
