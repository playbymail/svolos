<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * The kinds of unit that exist in the game.
 *
 * The catalogue, and it is code rather than a table for the reason `PlanetType` and
 * `PlanetGenerator::DEPOSIT_DICE` are: game content here is a thing the rules read, so it belongs
 * where static analysis can see it and a unit test can sweep it. There are no content tables in this
 * application, and `DatabaseSeeder` is deliberately a manifest that creates nothing. The decisive
 * question is not how many kinds there will be, it is who edits the numbers — they are tuned in the
 * repository, they do not vary per game, and no gamemaster sets them. That is a code shape. What the
 * enum buys over a table is a `match` with no `default` arm: adding a kind is a compile-time error at
 * every decision site rather than a runtime surprise.
 *
 * ## Measures are hundredths, and that is on purpose
 *
 * A unit's mass is 0.5 MU, not 50. Every measure here is stored as an integer at `SCALE`, because
 * capacity rules are *comparisons* — "does this fit" — and in floating point `0.1 + 0.2 > 0.3` is
 * true. A colony holding thousands of units would meet that. `format()` is the one place a stored
 * measure becomes the number a report prints, and a third decimal place is a change to `SCALE`
 * alone.
 *
 * ## Every case carries a mass and two volumes
 *
 * `assembledVolume()` is the room a unit takes put together and ready to use; `disassembledVolume()`
 * is the room it takes packed and crated. Both take a technology level, as `mass()` does, because a
 * kind's measures may depend on it — `LifeSupport` is 8 × TL MU and 8 × TL VU, and 4 × TL crated. **Which of the two applies is decided by the inventory a
 * unit sits in, not by the unit** — see `Inventory::usesDisassembledVolume()` — and `volumeIn()` is
 * where the two meet. Cargo is the only inventory measured at the disassembled volume.
 *
 * Assembled volume is usually twice disassembled volume, though the glossary is explicit that it
 * need not be, so the two are written out rather than derived.
 *
 * ## Only the structural kinds are settled
 *
 * `Structure` and `LightStructure` carry the real numbers and the real report codes. The other
 * nine are **placeholders**: their measures were written when there was one volume and no scale, and
 * they are carried across at `SCALE` unchanged with a disassembled volume of half their assembled
 * one. They were also sized against a structural unit that weighed ten times what `Structure` now
 * weighs, so expect them to move when their measures are settled. Nothing reads any of it yet.
 *
 * Two kinds the category table names — `CSGD` (consumer goods, a `Commodity`) and `LSU` (life
 * support, the `Static` category's one member) — are **not here yet**, because no mass or volume has
 * been given for either and a kind without measures fails `UnitTypeTest` as a half-defined one.
 *
 * ## `abbreviation()` is nullable, and that is the pressure to finish
 *
 * A report and an order name a kind by a short code — `STRC`, `STRL`, `FOOD`. Six kinds have one:
 * the two structural, and `FOOD`, `FUEL`, `METL` and `MNRL` from the category table. The rest answer
 * `null` rather than being handed an invented code that would then be hard to change, and
 * `UnitTypeTest` lists exactly which are still unnamed, so the gap is visible and shrinking rather
 * than forgotten. It also asserts no two kinds share a code, which is the thing that would make an
 * order ambiguous.
 */
enum UnitType: string
{
    /**
     * What every measure on this enum is multiplied by.
     *
     * Mass is in MU and both volumes are in VU, each stored as tenths: a mass of `5` is 0.5 MU.
     *
     * It has been hundredths and thousandths. It is tenths now because the catalogue was rewritten so
     * that **every measure is whole except a crated volume**, and the only fractions left anywhere are
     * halves. `format()` reads its decimal places off this constant rather than hard-coding them, so
     * moving it stays a one-line change.
     */
    public const int SCALE = 10;

    /**
     * The value stored for a kind that has no technology level at all.
     *
     * Zero rather than `null`, and the reason is the unique key on `units`: SQLite — like most
     * engines — treats `NULL`s as distinct in a unique index, so a nullable column would let a
     * second `(entity, food, cargo, NULL)` row exist beside the first and quietly break the one
     * guarantee that table makes. A sentinel keeps the key honest, and it is the *right* value for
     * these kinds rather than a placeholder: `hasTechnologyLevel()` says which ones, and
     * `UnitHolding` refuses any other pairing.
     */
    public const int NO_TECHNOLOGY_LEVEL = 0;

    /**
     * How much more room light structure encloses than structure does, for the same mass.
     *
     * **This is the whole difference between the two structural kinds.** They weigh the same, they
     * crate the same, and a light structural unit encloses ten times the space — thin walls holding
     * more air per tonne of material. Setting it to 1 would make them one kind with two names.
     */
    public const int LIGHT_STRUCTURE_FACTOR = 10;

    /** The lowest technology level a kind that has one may be built at. */
    public const int MINIMUM_TECHNOLOGY_LEVEL = 1;

    /** The highest technology level a kind that has one may be built at. */
    public const int MAXIMUM_TECHNOLOGY_LEVEL = 10;

    /* The frame: a ship's hull, a colony's buildings. */
    case Structure = 'structure';
    case LightStructure = 'light_structure';
    case Engine = 'engine';
    case LifeSupport = 'life_support';
    case Mine = 'mine';
    case Factory = 'factory';
    case Fuel = 'fuel';
    case Food = 'food';
    case ConsumerGoods = 'consumer_goods';
    case Metals = 'metals';
    case Minerals = 'minerals';
    case Machinery = 'machinery';
    case Supplies = 'supplies';

    /**
     * Get the number a report prints for a measure held at this enum's scale.
     *
     * The one place hundredths become a decimal. Trailing zeros are kept so that a column of
     * measures lines up: 0.50 rather than 0.5.
     */
    public static function format(int $measure): string
    {
        return number_format($measure / self::SCALE, strlen((string) self::SCALE) - 1);
    }

    /**
     * Get the stored form of a measure written the way the rules write it.
     *
     * The inverse of `format()`, and the only place a decimal appears in this file. It exists so the
     * catalogue below reads like the sheet it came from — `measure(0.005)` rather than `5` — because
     * these numbers are the content and a transposed digit in an integer literal is invisible.
     *
     * The float is a *literal* being converted once, not a measure being carried around; `round()`
     * is what keeps `0.1 * 1000` from landing on 99. Nothing outside this class sees one.
     */
    private static function measure(float $units): int
    {
        return (int) round($units * self::SCALE);
    }

    /**
     * Get the whole VU a measured volume actually occupies.
     *
     * **A part-used VU is a used VU.** A holding is grouped by inventory, kind and technology level —
     * which is exactly one `units` row — its volume is summed, and the total is then rounded *up* to
     * the next whole VU. Fifty STRC-5 crated come to 125 VU and occupy 125; forty-nine come to 122.5
     * and occupy 123.
     *
     * The rounding is a deliberate small penalty against stowing, which otherwise pays for itself
     * many times over: crating a structural unit shrinks it from `TL²` VU to half a tonne's worth. It
     * is charged **per holding rather than per unit** — per unit it would be a tax of up to half a VU
     * on every single crate, which is not small at all.
     *
     * Assembled volumes are always whole, so this only ever moves a crated total. Applying it
     * everywhere anyway costs nothing and means a fraction appearing somewhere new cannot slip
     * through uncharged.
     *
     * Integer arithmetic rather than `ceil()`: the measures are integers at `SCALE` and there is no
     * reason to route them through a float to round them.
     */
    public static function roundUpToWholeVolume(int $volume): int
    {
        return intdiv($volume + self::SCALE - 1, self::SCALE) * self::SCALE;
    }

    /**
     * Get the category this kind belongs to, or null where none has been settled.
     *
     * Null rather than a guess, for the reason `abbreviation()` is null: a category chosen here to
     * fill the arm is one somebody later reads as a decision. `UnitTypeTest` names the kinds that
     * are still without one.
     *
     * `Machinery` and `Supplies` are the two. Neither appears in the category table, and neither
     * reads unambiguously as `Infrastructure`, `Commodity` or `Resource` from its name alone.
     */
    public function category(): ?UnitCategory
    {
        return match ($this) {
            self::Structure, self::LightStructure => UnitCategory::Structural,
            self::Fuel, self::Metals, self::Minerals => UnitCategory::Resource,
            self::Food, self::ConsumerGoods => UnitCategory::Commodity,
            self::Engine => UnitCategory::Propulsion,
            self::LifeSupport => UnitCategory::Static,
            self::Mine, self::Factory => UnitCategory::Infrastructure,
            self::Machinery, self::Supplies => null,
        };
    }

    /**
     * Determine whether this kind is built at a technology level.
     *
     * Most kinds are. The exceptions are the raw commodities — a tonne of food is a tonne of food,
     * and there is no better one — which is why they are shown as `FOOD` rather than `FOOD-0`.
     *
     * `CSGD`, `FOOD`, `FUEL`, `METL` and `MNRL` were given as having none, which settles `Food`,
     * `Fuel`, `Metals` and `Minerals` here. The structural kinds have one. **`Machinery` and
     * `Supplies` are still a guess** — neither has a code or a category yet — and `UnitTypeTest`
     * spells the split out so that correcting it is one edit against a list.
     */
    public function hasTechnologyLevel(): bool
    {
        return match ($this) {
            self::Structure, self::LightStructure, self::LifeSupport,
            self::Engine, self::Mine, self::Factory, self::Machinery => true,
            self::Fuel, self::Food, self::ConsumerGoods,
            self::Metals, self::Minerals, self::Supplies => false,
        };
    }

    /**
     * Get the name a report gives this kind at a technology level: `STRL-10`, or `FOOD`.
     *
     * A kind with no technology level is named by its code alone. Null when the kind has no report
     * code yet — see `abbreviation()`.
     */
    public function reportName(int $technologyLevel): ?string
    {
        $abbreviation = $this->abbreviation();

        if ($abbreviation === null) {
            return null;
        }

        return $this->hasTechnologyLevel()
            ? sprintf('%s-%d', $abbreviation, $technologyLevel)
            : $abbreviation;
    }

    /**
     * Get the human readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Structure',
            self::LightStructure => 'Light Structure',
            self::Engine => 'Engine',
            self::LifeSupport => 'Life Support',
            self::Mine => 'Mine',
            self::Factory => 'Factory',
            self::Fuel => 'Fuel',
            self::Food => 'Food',
            self::ConsumerGoods => 'Consumer Goods',
            self::Metals => 'Metals',
            self::Minerals => 'Minerals',
            self::Machinery => 'Machinery',
            self::Supplies => 'Supplies',
        };
    }

    /**
     * Get the short code this kind is named by in reports and orders.
     *
     * Null for every kind that has not been given one yet. See the class docblock.
     */
    public function abbreviation(): ?string
    {
        return match ($this) {
            self::Structure => 'STRC',
            self::LightStructure => 'STRL',
            self::LifeSupport => 'LSU',
            self::Food => 'FOOD',
            self::ConsumerGoods => 'CSGD',
            self::Fuel => 'FUEL',
            self::Metals => 'METL',
            self::Minerals => 'MNRL',
            self::Engine, self::Mine, self::Factory,
            self::Machinery, self::Supplies => null,
        };
    }

    /**
     * Get what one of these weighs, in MU at `SCALE`.
     *
     * **A measure can depend on the technology level**, which is why every one of them takes it.
     * `LifeSupport` is the first: a TL-10 life support unit weighs ten times a TL-1 one, because it
     * is a bigger installation keeping more people alive rather than the same one built better.
     * Every other kind is flat today, and the parameter costs them nothing.
     */
    public function mass(int $technologyLevel): int
    {
        $this->assertTechnologyLevel($technologyLevel);

        return match ($this) {
            self::Structure, self::LightStructure => self::measure(1) * $technologyLevel,
            self::LifeSupport => self::measure(8) * $technologyLevel,
            self::Engine => self::measure(25),
            self::Mine => self::measure(40),
            self::Factory => self::measure(60),
            self::Machinery => self::measure(2),
            self::Food, self::ConsumerGoods => self::measure(6),
            self::Fuel, self::Metals, self::Minerals, self::Supplies => self::measure(1),
        };
    }

    /**
     * Get how much room one of these takes assembled, in VU at `SCALE`.
     *
     * Higher than the mass for everything that is mostly air or mostly shape, and equal to it for the
     * two ores, which are the densest thing anybody carries.
     *
     * **The structural kinds depend on what they were assembled for**, which is why this takes an
     * `EntityType` and the crated measure does not: a crate is a crate wherever it is going, but a
     * wall built into a hull is not the same wall built around a field. `TL²` for a ship or an
     * orbital colony, `TL² × 2` enclosed, `TL² × 10` in the open air — see
     * `EntityType::structuralVolumeMultiplier()`.
     *
     * `LightStructure` is the same formula times `LIGHT_STRUCTURE_FACTOR`. The two kinds weigh the
     * same and crate the same; light structure simply encloses ten times the room, which is the whole
     * difference between them and the only place it appears.
     */
    public function assembledVolume(int $technologyLevel, EntityType $assembledFor): int
    {
        $this->assertTechnologyLevel($technologyLevel);

        return match ($this) {
            self::Structure => self::measure(1) * $technologyLevel ** 2
                * $assembledFor->structuralVolumeMultiplier(),
            self::LightStructure => self::measure(1) * $technologyLevel ** 2
                * $assembledFor->structuralVolumeMultiplier() * self::LIGHT_STRUCTURE_FACTOR,
            self::LifeSupport => self::measure(8) * $technologyLevel,
            self::Engine => self::measure(20),
            self::Mine => self::measure(60),
            self::Factory => self::measure(90),
            self::Machinery => self::measure(3),
            self::Food, self::ConsumerGoods => self::measure(6),
            self::Fuel, self::Supplies => self::measure(2),
            self::Metals, self::Minerals => self::measure(1),
        };
    }

    /**
     * Get how much room one of these takes packed and crated, in VU at `SCALE`.
     */
    public function disassembledVolume(int $technologyLevel): int
    {
        $this->assertTechnologyLevel($technologyLevel);

        return match ($this) {
            self::Structure, self::LightStructure => self::measure(0.5) * $technologyLevel,
            self::LifeSupport => self::measure(4) * $technologyLevel,
            self::Engine => self::measure(10),
            self::Mine => self::measure(30),
            self::Factory => self::measure(45),
            self::Machinery => self::measure(1.5),
            self::Food, self::ConsumerGoods => self::measure(3),
            self::Fuel, self::Supplies => self::measure(1),
            self::Metals, self::Minerals => self::measure(0.5),
        };
    }

    /**
     * Get how much room one of these takes in a given inventory, in VU at `SCALE`.
     *
     * The inventory decides which of the two volumes applies, so this is the measure every capacity
     * question should ask for. Reading `assembledVolume()` directly is right only when the question
     * really is about the assembled state regardless of where the unit is.
     */
    public function volumeIn(Inventory $inventory, int $technologyLevel, EntityType $assembledFor): int
    {
        return $inventory->usesDisassembledVolume()
            ? $this->disassembledVolume($technologyLevel)
            : $this->assembledVolume($technologyLevel, $assembledFor);
    }

    /**
     * Refuse a technology level this kind cannot be built at.
     *
     * The one definition of the rule, called by every measure and by `App\Generation\UnitHolding`'s
     * constructor. It lives here rather than there because a measure can *depend* on the level:
     * `LifeSupport->mass(0)` would otherwise return zero and flow into a capacity calculation as a
     * unit that weighs nothing, which is a wrong answer rather than an error.
     *
     * @throws InvalidArgumentException
     */
    public function assertTechnologyLevel(int $technologyLevel): void
    {
        if (! $this->hasTechnologyLevel()) {
            if ($technologyLevel !== self::NO_TECHNOLOGY_LEVEL) {
                throw new InvalidArgumentException(sprintf('%s has no technology level.', $this->label()));
            }

            return;
        }

        if ($technologyLevel < self::MINIMUM_TECHNOLOGY_LEVEL || $technologyLevel > self::MAXIMUM_TECHNOLOGY_LEVEL) {
            throw new InvalidArgumentException(sprintf(
                '%s is built at a technology level from %d to %d.',
                $this->label(),
                self::MINIMUM_TECHNOLOGY_LEVEL,
                self::MAXIMUM_TECHNOLOGY_LEVEL,
            ));
        }
    }

    /**
     * Get the inventories a holding of this kind may legally sit in.
     *
     * **Components is the closed one.** It means the frame and systems of the entity itself, so
     * only the kinds an entity is *built from* may be assigned to it. Everything else is either
     * being carried (`Cargo`) or being used (`Operational`), and every kind can be both of those:
     * anything can be crated, and anything can be put to work or drawn on.
     *
     * Mines and factories are the mirror image — they are never components, because a colony's
     * mine is a thing it operates rather than a thing it is made of.
     *
     * Written out case by case rather than with a `default` arm: a `default` would quietly give a new
     * kind the commonest answer, and deciding where a new kind may sit is the whole of adding one.
     *
     * @return list<Inventory>
     */
    public function inventories(): array
    {
        return match ($this) {
            self::Structure, self::LightStructure,
            self::Engine, self::LifeSupport => [Inventory::Components, Inventory::Cargo],
            self::Mine, self::Factory,
            self::Fuel, self::Food, self::ConsumerGoods, self::Metals,
            self::Minerals, self::Machinery, self::Supplies => [Inventory::Cargo, Inventory::Operational],
        };
    }

    /**
     * Determine whether a holding of this kind may sit in an inventory.
     */
    public function allows(Inventory $inventory): bool
    {
        return in_array($inventory, $this->inventories(), true);
    }
}
