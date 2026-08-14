<?php

namespace App\Models;

use App\Enums\AssetAssignment;
use App\Enums\AssetType;
use Carbon\CarbonImmutable;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity of one kind of asset, held by one entity in one assignment.
 *
 * Assets are owned by entities and by nothing else: there is no unowned asset, and no asset belongs
 * directly to a seat or to a planet. What an entity is made of, what it is carrying and what it is
 * using are all rows here, told apart by `assignment`.
 *
 * `(entity_id, type, assignment)` is unique, so a row is the whole answer to "how much of this does it
 * have, here". Individual units cannot differ from one another — no condition, no damage, no name —
 * and nothing in the rules asks that yet.
 *
 * Which assignments a kind may sit in is a rule on `App\Enums\AssetType`, enforced when a holding is
 * built rather than by a constraint on this table: `App\Generation\AssetHolding` refuses an illegal
 * pairing, and it is the only thing that describes a row before it is written.
 *
 * No `#[Fillable]`, like every other model a generator writes.
 *
 * @property int $id
 * @property int $entity_id
 * @property AssetType $type
 * @property AssetAssignment $assignment
 * @property int $quantity
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Entity $entity
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
            'assignment' => AssetAssignment::class,
            'quantity' => 'integer',
        ];
    }
}
