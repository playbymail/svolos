<?php

namespace Database\Factories;

use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Models\Entity;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Ten tonnes of metals in the hold of an entity of its own. Fixed rather than drawn from faker, for
     * the reason `PlanetFactory`'s are: a test that cares sets the value, and one that does not should
     * not have its assertions shift underneath it.
     *
     * `(entity_id, type, inventory, technology_level)` is unique, so several units on the **same**
     * entity need to differ in one of those — `->sequence(...)` over `type` is the idiom.
     *
     * Metals have no technology level, so the default is `NO_TECHNOLOGY_LEVEL`. A test making a kind
     * that *has* one must set `technology_level` as well, or the row will contradict the catalogue.
     *
     * Metals in cargo rather than something in components, because most kinds may not be
     * components at all: the default has to be a pairing every kind allows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory(),
            'type' => UnitType::Metals,
            'inventory' => Inventory::Cargo,
            'technology_level' => UnitType::NO_TECHNOLOGY_LEVEL,
            'quantity' => 10,
        ];
    }
}
