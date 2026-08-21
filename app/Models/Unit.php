<?php

namespace App\Models;

use App\Enums\Inventory;
use App\Enums\UnitType;
use Carbon\CarbonImmutable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity of one kind of unit, held by one entity in one inventory.
 *
 * Units are owned by entities and by nothing else: there is no unowned unit, and no unit belongs
 * directly to a seat or to a planet. What an entity is made of, what it is carrying and what it is
 * using are all rows here, told apart by `inventory`.
 *
 * `(entity_id, type, inventory, technology_level)` is unique, so a row is the whole answer to "how
 * much of this does it have, at this level, here". A ship built with `STRL-10` that carries crated
 * `STRL-2` and runs `STRL-8` is three rows. Beyond kind and level, individual units cannot differ
 * from one another — no condition, no damage, no name — and nothing in the rules asks that yet.
 *
 * Which inventories a kind may sit in is a rule on `App\Enums\UnitType`, enforced when a holding is
 * built rather than by a constraint on this table: `App\Generation\UnitHolding` refuses an illegal
 * pairing, and it is the only thing that describes a row before it is written.
 *
 * No `#[Fillable]`, like every other model a generator writes.
 *
 * @property int $id
 * @property int $entity_id
 * @property UnitType $type
 * @property Inventory $inventory
 * @property int $technology_level
 * @property int $quantity
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Entity $entity
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    /**
     * Get the entity that owns this.
     *
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Get what this holding weighs in total, in MU at `UnitType::SCALE`.
     */
    public function mass(): int
    {
        return $this->type->mass($this->technology_level) * $this->quantity;
    }

    /**
     * Get how much room this holding takes in total, in VU at `UnitType::SCALE`.
     *
     * Measured at the volume its **inventory** asks for: crated in cargo, assembled anywhere else —
     * and, for the structural kinds, at the volume their **entity** asks for, since a wall built into
     * a hull is not the same wall built around a field. That is what the `entity` load is for: a row
     * cannot answer this alone. Eager-load `entity` when calling it over a collection.
     */
    public function volume(): int
    {
        $this->loadMissing('entity');

        return $this->type->volumeIn($this->inventory, $this->technology_level, $this->entity->type)
            * $this->quantity;
    }

    /**
     * Get the name a report gives this row: `STRL-10`, or `FOOD`.
     *
     * Null when the kind has no report code yet — see `UnitType::abbreviation()`.
     */
    public function reportName(): ?string
    {
        return $this->type->reportName($this->technology_level);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
            'inventory' => Inventory::class,
            'technology_level' => 'integer',
            'quantity' => 'integer',
        ];
    }
}
