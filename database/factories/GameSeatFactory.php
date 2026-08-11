<?php

namespace Database\Factories;

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameSeat>
 */
class GameSeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is an active player seat, matching both column defaults.
     *
     * The account is a plain member on purpose. A seat says nothing about the application role its
     * holder has, and a factory that quietly made every seat-holder an administrator would make the
     * role-separation tests pass for the wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'role' => GameRole::Player,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the seat holds the gamemaster role for its game.
     *
     * There is no matching `player()` state: `player` is the factory default and the column default.
     *
     * This state grants **no** application permissions — a gamemaster seat is not an administrator.
     * `tests/Feature/Admin/GameRoleSeparationTest.php` uses it to prove exactly that.
     */
    public function gamemaster(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => GameRole::Gamemaster,
        ]);
    }

    /**
     * Indicate that the seat has been retired.
     *
     * Retiring is the *only* way a seat leaves a game — there is no destroy endpoint, because engine
     * history keeps referring to the seat. A retired seat still occupies the account's place in the
     * unique index on `(game_id, user_id)`, so it still blocks a second seat for that account, and
     * bringing the account back is a reactivation of this row.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
