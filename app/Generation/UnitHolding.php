<?php

namespace App\Generation;

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use InvalidArgumentException;

/**
 * A quantity of one kind of unit, in one inventory.
 *
 * The unit the starting kits are written in, and the shape of one `units` row before it is a row:
 * `(entity, type, inventory, technology_level)` is unique in that table, so a holding is the whole
 * of what is known about a kind an entity has at one level in one place. A ship built with `STRL-10`
 * carrying crated `STRL-2` and running `STRL-8` is three holdings of the same kind, which is why the
 * level is part of the key rather than an attribute hanging off it.
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
        public int $technologyLevel = UnitType::NO_TECHNOLOGY_LEVEL,
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

        $type->assertTechnologyLevel($technologyLevel);
    }

    /**
     * Get the name a report gives this holding: `STRL-10`, or `FOOD`.
     *
     * Null when the kind has no report code yet — see `UnitType::abbreviation()`.
     */
    public function reportName(): ?string
    {
        return $this->type->reportName($this->technologyLevel);
    }

    /**
     * Get what this holding weighs in total, in MU at `UnitType::SCALE`.
     */
    public function mass(): int
    {
        return $this->type->mass($this->technologyLevel) * $this->quantity;
    }

    /**
     * Get how much room this holding takes in total, in VU at `UnitType::SCALE`.
     *
     * Measured at the volume its **inventory** asks for: crated in cargo, assembled anywhere else.
     *
     * The entity kind is a parameter rather than a property because a holding is written before an
     * entity exists — `StartingUnits::for()` builds a kit *for* a kind, and the caller that asked for
     * it is the one that knows. Only the structural kinds read it; see
     * `UnitType::assembledVolume()`.
     */
    public function volume(EntityType $assembledFor): int
    {
        return $this->type->volumeIn($this->inventory, $this->technologyLevel, $assembledFor) * $this->quantity;
    }
}
