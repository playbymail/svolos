<?php

namespace Database\Factories;

use App\Enums\PlanetType;
use App\Models\GenerationRun;
use App\Models\Planet;
use App\Models\Star;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Planet>
 */
class PlanetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A rocky world in the first orbit of a star of its own. `ordinal` is unique within a star, so
     * several planets around the *same* star need distinct ones —
     * `->sequence(fn ($sequence) => ['ordinal' => $sequence->index + 1])` is the idiom, the same one
     * `StarFactory` documents for stars within a stellium.
     *
     * The attributes are fixed rather than drawn from faker: a test that cares about a value sets it,
     * and a test that does not should not have its assertions shift underneath it. Randomising here
     * would also quietly disagree with `PlanetGenerator`'s tables, which are the real source of what a
     * planet may look like.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'star_id' => Star::factory(),
            'generation_run_id' => GenerationRun::factory(),
            'ordinal' => 1,
            'type' => PlanetType::Rocky,
            'habitability' => 12,
            'fuel' => 2,
            'metals' => 7,
            'minerals' => 7,
        ];
    }

    /**
     * Make the planet an asteroid field: no habitability, and the deposits that pay for it.
     */
    public function asteroids(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PlanetType::Asteroids,
            'habitability' => 0,
            'fuel' => 1,
            'metals' => 22,
            'minerals' => 22,
        ]);
    }
}
