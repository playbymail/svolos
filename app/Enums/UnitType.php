<?php

namespace App\Enums;

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
 * is the room it takes packed and crated. **Which of the two applies is decided by the inventory a
 * unit sits in, not by the unit** — see `Inventory::usesDisassembledVolume()` — and `volumeIn()` is
 * where the two meet. Cargo is the only inventory measured at the disassembled volume.
 *
 * Assembled volume is usually twice disassembled volume, though the glossary is explicit that it
 * need not be, so the two are written out rather than derived.
 *
 * ## Only the structural kinds are settled
 *
 * `Structural` and `LightStructural` carry the real numbers and the real report codes. The other
 * nine are **placeholders**: their measures were written when there was one volume and no scale, and
 * they are carried across at `SCALE` unchanged with a disassembled volume of half their assembled
 * one. They were also sized against a structural unit that weighed ten times what `Structural` now
 * weighs, so expect them to move when their categories are settled. Nothing reads any of it yet.
 *
 * ## `abbreviation()` is nullable, and that is the pressure to finish
 *
 * A report and an order name a kind by a short code — `STRU`, `LSTR`. Only the structural kinds have
 * been given one, so the rest answer `null` rather than being handed an invented code that would
 * then be hard to change. `UnitTypeTest` lists exactly which kinds are still unnamed, so the gap is
 * visible and shrinking rather than forgotten. It also asserts no two kinds share a code, which is
 * the thing that makes an order ambiguous.
 */
enum UnitType: string
{
    /**
     * What every measure on this enum is multiplied by.
     *
     * Mass is in MU and both volumes are in VU, each stored as hundredths: a mass of `50` is 0.5 MU.
     */
    public const int SCALE = 100;

    /* The frame: a ship's hull, a colony's buildings. */
    case Structural = 'structural';
    case LightStructural = 'light_structural';
    case Engine = 'engine';
    case Mine = 'mine';
    case Factory = 'factory';
    case Fuel = 'fuel';
    case Food = 'food';
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
        return number_format($measure / self::SCALE, 2);
    }

    /**
     * Get the human readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Structural => 'Structural',
            self::LightStructural => 'Light Structural',
            self::Engine => 'Engine',
            self::Mine => 'Mine',
            self::Factory => 'Factory',
            self::Fuel => 'Fuel',
            self::Food => 'Food',
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
            self::Structural => 'STRU',
            self::LightStructural => 'LSTR',
            self::Engine, self::Mine, self::Factory,
            self::Fuel, self::Food, self::Metals,
            self::Minerals, self::Machinery, self::Supplies => null,
        };
    }

    /**
     * Get what one of these weighs, in MU at `SCALE`.
     */
    public function mass(): int
    {
        return match ($this) {
            self::Structural => 50,
            self::LightStructural => 5,
            self::Engine => 2_500,
            self::Mine => 4_000,
            self::Factory => 6_000,
            self::Machinery => 200,
            self::Fuel, self::Food, self::Metals, self::Minerals, self::Supplies => 100,
        };
    }

    /**
     * Get how much room one of these takes assembled, in VU at `SCALE`.
     *
     * Higher than the mass for everything that is mostly air or mostly shape, and equal to it for the
     * two ores, which are the densest thing anybody carries.
     */
    public function assembledVolume(): int
    {
        return match ($this) {
            self::Structural => 100,
            self::LightStructural => 10,
            self::Engine => 2_000,
            self::Mine => 6_000,
            self::Factory => 9_000,
            self::Machinery => 300,
            self::Fuel, self::Food, self::Supplies => 200,
            self::Metals, self::Minerals => 100,
        };
    }

    /**
     * Get how much room one of these takes packed and crated, in VU at `SCALE`.
     */
    public function disassembledVolume(): int
    {
        return match ($this) {
            self::Structural => 50,
            self::LightStructural => 5,
            self::Engine => 1_000,
            self::Mine => 3_000,
            self::Factory => 4_500,
            self::Machinery => 150,
            self::Fuel, self::Food, self::Supplies => 100,
            self::Metals, self::Minerals => 50,
        };
    }

    /**
     * Get how much room one of these takes in a given inventory, in VU at `SCALE`.
     *
     * The inventory decides which of the two volumes applies, so this is the measure every capacity
     * question should ask for. Reading `assembledVolume()` directly is right only when the question
     * really is about the assembled state regardless of where the unit is.
     */
    public function volumeIn(Inventory $inventory): int
    {
        return $inventory->usesDisassembledVolume()
            ? $this->disassembledVolume()
            : $this->assembledVolume();
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
            self::Structural, self::LightStructural,
            self::Engine => [Inventory::Components, Inventory::Cargo],
            self::Mine, self::Factory,
            self::Fuel, self::Food, self::Metals,
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
