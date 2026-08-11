<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has confirmed two-factor authentication configured.
     *
     * The secret is a real base32 TOTP secret rather than a placeholder so tests can derive
     * the code an authenticator app would currently show and exercise the challenge for real.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(
                app(TwoFactorAuthenticationProvider::class)->generateSecretKey()
            ),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                (string) json_encode(Collection::times(8, fn (): string => RecoveryCode::generate())->all())
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model has started, but not confirmed, two-factor enrolment.
     */
    public function withUnconfirmedTwoFactor(): static
    {
        return $this->withTwoFactor()->state(fn (array $attributes): array => [
            'two_factor_confirmed_at' => null,
        ]);
    }
}
