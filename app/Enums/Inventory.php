<?php

namespace App\Enums;

/**
 * What a unit is currently doing for the entity that owns it.
 *
 * Three states, and every unit an entity holds is in exactly one of them:
 *
 * - **Components** — the units that make up the frame and systems of the entity itself. A ship's
 *   hull and its engines; a colony's buildings. These are what the entity *is*, so they are the ones
 *   the movement rules will read.
 * - **Cargo** — crated up for compact storage. A crated mine is not mining and a crated engine is not
 *   pushing anything; it is being carried.
 * - **Operational** — everything that is neither. A colony's working mines and factories, and the
 *   stores it is drawing on.
 *
 * ## It is stored, because somebody decided it
 *
 * The same mine is one kind of thing whether it sits in a hold or on a hillside, and moving it between
 * the two is an act rather than a consequence — so the inventory is a column, the way `GameStatus` is.
 * Contrast `planets.zone`, which has no column because it is a function of the ordinal and the star's
 * planet count and could only ever disagree with them.
 *
 * Which inventories a kind may legally sit in belongs to the kind, not here: see
 * `UnitType::inventories()`.
 */
enum Inventory: string
{
    case Components = 'components';
    case Cargo = 'cargo';
    case Operational = 'operational';

    /**
     * Get the human readable label for the inventory.
     */
    public function label(): string
    {
        return match ($this) {
            self::Components => 'Components',
            self::Cargo => 'Cargo',
            self::Operational => 'Operational',
        };
    }
}
