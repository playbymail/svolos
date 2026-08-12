<?php

namespace Database\Factories;

use App\Enums\GenerationStage;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Star;
use App\Models\Stellium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stellium>
 */
class StelliumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A stellium with no stars, at a location of its own. Stars are added by the caller, because how
     * many there are is the thing a test about stelliums is usually asserting.
     *
     * The run is a **stelliums** run at the location's own game: a stellium never comes from the same
     * run as the location under it, since the two stages are generated and accepted separately.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'generation_run_id' => function (array $attributes): int {
                $location = Location::query()->findOrFail((int) $attributes['location_id']);

                return GenerationRun::factory()
                    ->stage(GenerationStage::Stelliums)
                    ->create(['game_id' => $location->game_id])
                    ->id;
            },
        ];
    }

    /**
     * Give the stellium a number of stars, numbered from one.
     *
     * Through the star factory rather than `$stellium->stars()->create()`: none of the generated models
     * declares a `#[Fillable]` list, because nothing about a generated world ever arrives from request
     * input — the generators write these rows themselves. Factories run unguarded, so this is the way
     * in that does not require opening one.
     */
    public function withStars(int $count): static
    {
        return $this->afterCreating(function (Stellium $stellium) use ($count): void {
            for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
                Star::factory()->for($stellium)->create(['ordinal' => $ordinal]);
            }
        });
    }
}
