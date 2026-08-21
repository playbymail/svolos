<?php

namespace Database\Factories;

use App\Enums\EntityType;
use App\Models\Entity;
use App\Models\GameSeat;
use App\Models\Planet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entity>
 */
class EntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A colony on a planet of its own, controlled by a seat of its own. The two are made independently
     * because nothing ties them — an entity may stand anywhere its seat's game reaches, and there is no
     * unique key or foreign key here that a mismatch would trip. A test that cares which planet or
     * which seat says so.
     *
     * `generation_run_id` is deliberately left null: a bare `Entity::factory()` is something built in
     * play rather than something a run placed. A test that wants the other one says
     * `->for($run)`, and having to say it is what keeps the distinction visible in the tests that turn
     * on it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_seat_id' => GameSeat::factory(),
            'planet_id' => Planet::factory(),
            'generation_run_id' => null,
            'type' => EntityType::OpenAirColony,
        ];
    }

    /**
     * Make it a colony: assembled where it stands, and never moved.
     */
    public function colony(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => EntityType::OpenAirColony]);
    }

    /**
     * Make it a ship: in orbit above its planet, and able to leave if it can.
     */
    public function ship(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => EntityType::Ship]);
    }
}
