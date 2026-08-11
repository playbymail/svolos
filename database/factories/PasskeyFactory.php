<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

/**
 * The Passkey model ships with the laravel/passkeys package and does not use HasFactory,
 * so instantiate this factory directly: `PasskeyFactory::new()->for($user)->create()`.
 *
 * @extends Factory<Passkey>
 */
class PasskeyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Passkey>
     */
    protected $model = Passkey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['MacBook Pro', 'iPhone', 'YubiKey', 'Windows Hello']),
            'credential_id' => Str::random(43),
            'credential' => ['publicKeyCredentialId' => Str::random(43)],
            'last_used_at' => null,
        ];
    }

    /**
     * Indicate that the passkey has been used to sign in.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_used_at' => now()->subDay(),
        ]);
    }
}
