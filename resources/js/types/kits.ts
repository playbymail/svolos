/**
 * A kit is what every player in one game begins holding: a colony's worth of units and a ship's
 * worth. `App\Generation\Kit` is the thing itself, and a gamemaster keeps their own in a private
 * library at `/gamemaster/kit-templates`.
 */

/**
 * One saved kit in a list, as shaped by `App\Concerns\PresentsKits::presentKitTemplate()`.
 *
 * `seed` and `file` are carried as two nullable facts rather than collapsed into one "how did this
 * arrive" string, because the screen shows them differently: a seed is a number somebody can reuse,
 * a filename is a document somebody still has. Both null means it was written by hand.
 */
export type KitTemplateSummary = {
    id: number;
    name: string;
    seed: number | null;
    file: string | null;
    entities: number;
    holdings: number;
    updated_at_diff: string;
};

/**
 * One holding: a quantity of one kind of unit, in one inventory, at one technology level.
 *
 * `(type, inventory, technology_level)` is what makes a holding unique inside an entity — it is the
 * `units` table's own key — so two rows agreeing on all three is a kit the server refuses.
 *
 * `report_name` is the code a report prints (`STRL-10`, or `FOOD`), and is **null** for the kinds
 * that have no code settled yet. The editor falls back to the label rather than inventing one.
 */
export type KitHolding = {
    type: string;
    inventory: string;
    technology_level: number;
    quantity: number;
    report_name: string | null;
};

/**
 * One kind of entity in a kit, and everything it begins holding.
 *
 * `mass` and `volume` are **formatted totals** computed on the server, not numbers to do arithmetic
 * with. Per-holding measures are deliberately not shipped: they are functions of the kind and the
 * quantity, so a client that computed them would be a second copy of `App\Enums\UnitType` that can
 * disagree with the first — see `.ai/rules/units.md`.
 */
export type KitEntity = {
    type: string;
    label: string;
    mass: string;
    volume: string;
    holdings: KitHolding[];
};

/**
 * A whole kit, as shaped by `App\Concerns\PresentsKits::presentKit()`.
 */
export type Kit = {
    seed: number | null;
    file: string | null;
    entities: KitEntity[];
};

/**
 * One kind of unit, with the rules the editor's pickers need.
 *
 * Shipped from `App\Enums\UnitType` rather than restated here on purpose. Which inventories a kind
 * may sit in and whether it carries a technology level are **rules**, and a second copy on the client
 * would eventually disagree with the enum — showing up as a holding the editor happily builds and the
 * server then refuses.
 */
export type CatalogueUnitType = {
    value: string;
    label: string;
    abbreviation: string | null;
    has_technology_level: boolean;
    inventories: string[];
};

/**
 * Everything the kit editor needs in order to offer only legal choices.
 */
export type UnitCatalogue = {
    inventories: { value: string; label: string }[];
    entity_types: { value: string; label: string }[];
    unit_types: CatalogueUnitType[];
    minimum_technology_level: number;
    maximum_technology_level: number;
    no_technology_level: number;
};
