<?php

namespace App\Generation;

use App\Enums\Inventory;
use App\Enums\UnitType;
use InvalidArgumentException;

/**
 * A quantity of one kind of unit, in one inventory.
 *
 * The unit the starting kits are written in, and the shape of one `units` row before it is a row:
 * `(entity, type, inventory)` is unique in that table, so a holding is the whole of what is known
 * about a kind an entity has in one place.
 *
 * ## The constructor is where the catalogue's one rule is enforced
 *
 * `UnitType::inventories()` says where a kind may sit, and this refuses to exist anywhere else. It is
 * an `InvalidArgumentException` rather than a `GenerationFailed` because nothing a gamemaster can post
 * reaches here — the kits are constants, so an illegal holding is a mistake in the source and should
 * fail the moment the file is loaded by a test, not become a message on a form.
 *
 * The quantity is not allowed to be zero. "None of this" is the absence of a holding, and a row saying
 * zero would be a second way to say it that every later count would have to remember to exclude.
 */
final readonly class UnitHolding
{
    public function __construct(
        public UnitType $type,
        public Inventory $inventory,
        public int $quantity,
    ) {
        if (! $type->allows($inventory)) {
            throw new InvalidArgumentException(
                sprintf('%s cannot be assigned to %s.', $type->label(), $inventory->label())
            );
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException(
                sprintf('A holding of %s needs a quantity of at least one.', $type->label())
            );
        }
    }

    /**
     * Get what this holding weighs in total, in tonnes.
     */
    public function mass(): int
    {
        return $this->type->mass() * $this->quantity;
    }

    /**
     * Get how much room this holding takes in total, in cubic metres.
     */
    public function volume(): int
    {
        return $this->type->volume() * $this->quantity;
    }
}
