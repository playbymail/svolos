<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is a game in setup with no seats, because that is the state every game starts in and
     * the one the create form produces.
     *
     * `short_name` is built to satisfy the same rule the validator enforces — uppercase `[A-Z0-9-]`
     * within 16 characters — so a factory-made game is one the application would have accepted.
     *
     * The one unique draw feeds **both** unique columns, so a game cannot collide with another game on
     * either of them from a single guarantee. Both columns are unique in the schema, and a collision
     * would surface as a database error in whatever unrelated test happened to make the second game.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = fake()->unique()->numerify('####');

        return [
            'name' => Str::title(fake()->word()).' Campaign '.$suffix,
            'short_name' => 'G-'.$suffix,
            'status' => GameStatus::Setup,
        ];
    }

    /**
     * Indicate that the game is being played.
     *
     * There is no matching `setup()` state: `setup` is both the factory default and the column default,
     * so a second state saying so would be a second place to keep in step.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Active,
        ]);
    }

    /**
     * Indicate that the game has stopped without ending.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Paused,
        ]);
    }

    /**
     * Indicate that the game has ended.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Completed,
        ]);
    }

    /**
     * Indicate that the game has been archived.
     *
     * This is the one status with behaviour attached to it, so it is the state most tests reach for:
     * `Game::unarchived()` excludes these rows.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GameStatus::Archived,
        ]);
    }
}
