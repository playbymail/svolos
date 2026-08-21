<?php

namespace App\Enums;

/**
 * The groupings a kind of unit belongs to.
 *
 * A category answers *what sort of thing is this*, where `UnitType` answers *which thing*. It is the
 * grouping reports and orders are organised by, and the level most rules are written at: ammunition
 * is consumed in combat whatever the round is, and living units change by demographics whoever they
 * are.
 *
 * Thirteen, settled together on 2026-08-21. They are declared alphabetically because that is the
 * order they were given in and no dependency runs between them — unlike `GenerationStage`, whose
 * declaration order *is* its dependency order.
 *
 * ## `Infrastructure` here is a category, not an inventory
 *
 * `Inventory::Components` was called `Infrastructure` until the day before this enum arrived, and
 * the rename was made for the glossary's sake rather than in anticipation of this. It happens to
 * have cleared the way: an infrastructure *category* — mines, factories, the installations that
 * produce something each turn — and a components *inventory* are different questions, and one word
 * answering both would have been read wrong by somebody eventually.
 *
 * ## Not every kind has one yet
 *
 * `UnitType::category()` returns `null` for the kinds whose category has not been settled, for the
 * reason `abbreviation()` does: a guess made here is a guess somebody later reads as a decision.
 * `UnitTypeTest` names them.
 */
enum UnitCategory: string
{
    case Ammunition = 'ammunition';
    case Cadre = 'cadre';
    case Commodity = 'commodity';
    case Infrastructure = 'infrastructure';
    case Living = 'living';
    case Propulsion = 'propulsion';
    case Recon = 'recon';
    case Resource = 'resource';
    case Static = 'static';
    case Structural = 'structural';
    case Technology = 'technology';
    case Transportation = 'transportation';
    case Weaponry = 'weaponry';

    /**
     * Get the human readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ammunition => 'Ammunition',
            self::Cadre => 'Cadre',
            self::Commodity => 'Commodity',
            self::Infrastructure => 'Infrastructure',
            self::Living => 'Living',
            self::Propulsion => 'Propulsion',
            self::Recon => 'Recon',
            self::Resource => 'Resource',
            self::Static => 'Static',
            self::Structural => 'Structural',
            self::Technology => 'Technology',
            self::Transportation => 'Transportation',
            self::Weaponry => 'Weaponry',
        };
    }

    /**
     * Get the sentence that says what belongs in this category.
     *
     * These are the definitions, not blurbs for a screen: they are what somebody deciding where a
     * new kind belongs should be reading, which is why they say what the category *does* rather than
     * listing what is currently in it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Ammunition => 'Expendable munitions consumed in combat.',
            self::Cadre => 'Roles filled by population units on temporary assignment; their food and consumer-goods needs are counted with the underlying population.',
            self::Commodity => 'Consumable goods that feed the population and set its standard of living.',
            self::Infrastructure => 'Assembled installations that produce output each turn (production, power, research).',
            self::Living => 'Population units whose counts change each turn through demographics.',
            self::Propulsion => 'Drives that move or maneuver an entity.',
            self::Recon => 'Sensor and probe equipment used to gather information.',
            self::Resource => 'Raw materials extracted from planetary deposits and consumed in production.',
            self::Static => 'Assembled support installation (life support).',
            self::Structural => 'Material assembled to enclose volume for ships and colonies.',
            self::Technology => 'Units used to advance or transfer Tech Level; may be non-physical.',
            self::Transportation => 'Units that move population and materials between entities at a planet.',
            self::Weaponry => 'Combat systems that inflict or deflect damage; most require assembly and crew.',
        };
    }

    /**
     * Get the kinds of unit that belong to this category.
     *
     * Reads `UnitType::category()` rather than keeping a second list, so the two cannot disagree.
     * Empty for every category whose kinds are not in the catalogue yet, which is most of them.
     *
     * @return list<UnitType>
     */
    public function types(): array
    {
        return array_values(array_filter(
            UnitType::cases(),
            fn (UnitType $type): bool => $type->category() === $this,
        ));
    }
}
