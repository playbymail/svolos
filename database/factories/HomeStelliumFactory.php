<?php

namespace Database\Factories;

use App\Enums\GenerationStage;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\HomeStellium;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeStellium>
 */
class HomeStelliumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A home at a location of its own, for a seat at that location's game, from a **home stellia** run
     * at the same game — three things that have to agree, and none of which the unique keys would catch
     * if they did not, since both are scoped to the run.
     *
     * The seat and the run are both derived from the location rather than made independently, because a
     * home whose seat belongs to another game is not a state the application can reach and a factory
     * that produced one would let a test pass against a world that cannot exist.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'game_seat_id' => function (array $attributes): int {
                return GameSeat::factory()
                    ->create(['game_id' => $this->gameOf($attributes)])
                    ->id;
            },
            'generation_run_id' => function (array $attributes): int {
                return GenerationRun::factory()
                    ->stage(GenerationStage::HomeStellia)
                    ->create(['game_id' => $this->gameOf($attributes)])
                    ->id;
            },
        ];
    }

    /**
     * Get the game the location belongs to, which everything else here has to belong to as well.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function gameOf(array $attributes): int
    {
        return Location::query()->findOrFail((int) $attributes['location_id'])->game_id;
    }
}
