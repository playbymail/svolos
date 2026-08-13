<?php

namespace Database\Factories;

use App\Models\AgentCredential;
use App\Models\GameSeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentCredential>
 */
class AgentCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The token is hashed here exactly as the real minting action hashes it, so a factory-built
     * credential cannot be authenticated with unless the test kept the plain text — which is the
     * same position an administrator is in after closing the panel.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_seat_id' => GameSeat::factory(),
            'token' => AgentCredential::hashToken(AgentCredential::generateToken()),
            'issued_by_id' => null,
            'last_used_at' => null,
        ];
    }

    /**
     * Store the hash of a known plain-text token, so a test can authenticate with it.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes): array => [
            'token' => AgentCredential::hashToken($token),
        ]);
    }

    /**
     * Indicate that the credential has been used.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_used_at' => now(),
        ]);
    }
}
