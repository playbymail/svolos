<?php

namespace Database\Factories;

use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GenerationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationRun>
 */
class GenerationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A pending first attempt at the cluster, because that is the state every run starts in and the one
     * the generate button produces. The seed is drawn the same way a game's is, so a factory-made run
     * carries a seed the application would have accepted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'stage' => GenerationStage::Cluster,
            'seed' => Game::randomSeed(),
            'attempt' => 1,
            'summary' => null,
            'accepted_at' => null,
            'superseded_at' => null,
        ];
    }

    /**
     * Indicate which stage the run belongs to.
     */
    public function stage(GenerationStage $stage): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => $stage,
        ]);
    }

    /**
     * Indicate that the run produced what the game now has.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * Indicate that the run was regenerated past.
     *
     * There is no matching `pending()` state: pending is the factory default, and a state saying so
     * would be a second place to keep in step.
     */
    public function superseded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'superseded_at' => now(),
        ]);
    }
}
