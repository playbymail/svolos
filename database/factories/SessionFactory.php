<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The `id` is minted the way the framework mints one — 40 random alphanumeric characters — so a
     * test can hand the same value to `TestCase::pinSessionId()` and have the row really be the
     * session making the request.
     *
     * `payload` mirrors what `DatabaseSessionHandler` writes (a base64 encoded serialised array).
     * Nothing in the application reads it back; it is here because the column is `not null`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::random(40),
            'user_id' => User::factory(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ];
    }

    /**
     * Indicate that the session belongs to nobody.
     *
     * The framework writes a row for a signed-out visitor too, which is why the administration
     * screen scopes its listing to `Session::authenticated()`.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }

    /**
     * Indicate the moment the session was last seen.
     */
    public function lastActiveAt(DateTimeInterface $moment): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_activity' => $moment->getTimestamp(),
        ]);
    }

    /**
     * Indicate the user agent the session was created from.
     */
    public function userAgent(?string $userAgent): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_agent' => $userAgent,
        ]);
    }
}
