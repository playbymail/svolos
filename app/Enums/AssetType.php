<?php

namespace App\Enums;

/**
 * The kinds of asset that exist in the game.
 *
 * The catalogue, and it is code rather than a table for the reason `PlanetType` and
 * `PlanetGenerator::DEPOSIT_DICE` are: game content here is a thing the rules read, so it belongs
 * where static analysis can see it and a unit test can sweep it. There are no content tables in this
 * application, and `DatabaseSeeder` is deliberately a manifest that creates nothing.
 *
 * ## Every case carries three things, and one of them is a rule
 *
 * `mass()` and `volume()` are per unit — tonnes and cubic metres — and are what a capacity rule will
 * eventually be written against. Nothing reads them yet; they are here because a catalogue that
 * describes only names would have to be revisited to say anything at all.
 *
 * `assignments()` is a rule and is enforced today: `App\Generation\AssetHolding` refuses to be built
 * with an assignment its type does not allow, so an illegal holding cannot reach the database through
 * the one thing that writes them. It is what makes the difference between the three assignments real
 * rather than decorative — **only `Structure` and `Engine` may be Infrastructure**, because
 * infrastructure is the frame and the systems of the entity itself and a crate of food is neither.
 *
 * The bulk commodities are counted in tonnes, so their mass is 1 a unit and they differ only in how
 * much room a tonne of them takes. The machinery is counted in whole installations.
 */
enum AssetType: string
{
    /* The frame: a ship's hull, a colony's buildings. */
    case Structure = 'structure';
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
     * Get the human readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Structure',
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
     * Get what one of these weighs, in tonnes.
     */
    public function mass(): int
    {
        return match ($this) {
            self::Structure => 5,
            self::Engine => 25,
            self::Mine => 40,
            self::Factory => 60,
            self::Machinery => 2,
            self::Fuel, self::Food, self::Metals, self::Minerals, self::Supplies => 1,
        };
    }

    /**
     * Get how much room one of these takes, in cubic metres.
     *
     * Higher than the mass for everything that is mostly air or mostly shape, and equal to it for the
     * two ores, which are the densest thing anybody carries.
     */
    public function volume(): int
    {
        return match ($this) {
            self::Structure => 10,
            self::Engine => 20,
            self::Mine => 60,
            self::Factory => 90,
            self::Machinery => 3,
            self::Fuel, self::Food, self::Supplies => 2,
            self::Metals, self::Minerals => 1,
        };
    }

    /**
     * Get the assignments a holding of this kind may legally sit in.
     *
     * **Infrastructure is the closed one.** It means the frame and systems of the entity itself, so
     * only the two kinds an entity is *built from* may be assigned to it. Everything else is either
     * being carried (`Cargo`) or being used (`Operational`), and every kind can be both of those:
     * anything can be crated, and anything can be put to work or drawn on.
     *
     * Mines and factories are the mirror image — they are never infrastructure, because a colony's
     * mine is a thing it operates rather than a thing it is made of.
     *
     * Written out case by case rather than with a `default` arm: a `default` would quietly give a new
     * kind the commonest answer, and deciding where a new kind may sit is the whole of adding one.
     *
     * @return list<AssetAssignment>
     */
    public function assignments(): array
    {
        return match ($this) {
            self::Structure, self::Engine => [AssetAssignment::Infrastructure, AssetAssignment::Cargo],
            self::Mine, self::Factory,
            self::Fuel, self::Food, self::Metals,
            self::Minerals, self::Machinery, self::Supplies => [AssetAssignment::Cargo, AssetAssignment::Operational],
        };
    }

    /**
     * Determine whether a holding of this kind may sit in an assignment.
     */
    public function allows(AssetAssignment $assignment): bool
    {
        return in_array($assignment, $this->assignments(), true);
    }
}
