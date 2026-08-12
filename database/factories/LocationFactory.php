<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GenerationRun;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Locations are placed on the axes at even distances rather than scattered at random, and the
     * arithmetic is worth the two lines: a factory drawing random points would collide with the unique
     * key on `(game_id, x, y, z)` often enough to flake unrelated tests, and would sometimes place two
     * locations closer together than `App\Generation\ClusterGenerator` ever would — which would make a
     * test about separation pass or fail for reasons that have nothing to do with the generator.
     *
     * The walk gives 42 distinct points: even magnitudes 2 to 14, on each of the three axes, in both
     * directions. Every one is inside the sphere and at least 2 from every other, so a factory-made
     * cluster satisfies the same rules a generated one does. A test that needs a *whole* cluster should
     * run the generator; this is for tests that need somewhere to hang a stellium.
     *
     * The run is created for the same game as the location, not a second game of its own.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ordinal = $this->faker->unique()->numberBetween(1, 42);
        $magnitude = 2 * (intdiv($ordinal - 1, 6) + 1);
        $axis = intdiv($ordinal - 1, 2) % 3;
        $distance = $ordinal % 2 === 0 ? -$magnitude : $magnitude;

        return [
            'game_id' => Game::factory(),
            'generation_run_id' => fn (array $attributes): int => GenerationRun::factory()
                ->create(['game_id' => $attributes['game_id']])
                ->id,
            'ordinal' => $ordinal,
            'x' => $axis === 0 ? $distance : 0,
            'y' => $axis === 1 ? $distance : 0,
            'z' => $axis === 2 ? $distance : 0,
        ];
    }

    /**
     * Place the location at a given point.
     */
    public function at(int $x, int $y, int $z): static
    {
        return $this->state(fn (array $attributes): array => [
            'x' => $x,
            'y' => $y,
            'z' => $z,
        ]);
    }
}
