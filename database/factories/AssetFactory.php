<?php

namespace Database\Factories;

use App\Enums\AssetAssignment;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Ten tonnes of metals in the hold of an entity of its own. Fixed rather than drawn from faker, for
     * the reason `PlanetFactory`'s are: a test that cares sets the value, and one that does not should
     * not have its assertions shift underneath it.
     *
     * `(entity_id, type, assignment)` is unique, so several assets on the **same** entity need distinct
     * types or assignments — `->sequence(...)` over `type` is the idiom.
     *
     * Metals in cargo rather than something in infrastructure, because most kinds may not be
     * infrastructure at all: the default has to be a pairing every kind allows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory(),
            'type' => AssetType::Metals,
            'assignment' => AssetAssignment::Cargo,
            'quantity' => 10,
        ];
    }
}
