<?php

namespace Database\Factories;

use App\Models\Star;
use App\Models\Stellium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Star>
 */
class StarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The first star of a stellium of its own. `ordinal` is unique within a stellium, so several stars
     * for the *same* stellium need distinct ones — either `StelliumFactory::withStars()`, which is what
     * most tests want, or `->sequence(fn ($sequence) => ['ordinal' => $sequence->index + 1])` when the
     * stars themselves are the subject.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stellium_id' => Stellium::factory(),
            'ordinal' => 1,
        ];
    }
}
