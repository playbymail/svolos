<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is a pending invitation for a member issued by an administrator, because that is
     * the state every invitation starts in and the one most tests want. `token` gets the hash of a
     * throwaway plain-text token: a test that needs to follow the link has to mint the token itself
     * (see the `pendingInvitation()` helper in `tests/Pest.php`), because nothing — not even a
     * factory — can recover the plain text from a stored row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token' => Invitation::hashToken(Invitation::generateToken()),
            'role' => UserRole::Member,
            'invited_by_id' => User::factory()->admin(),
            'expires_at' => now()->addDays(Invitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ];
    }

    /**
     * Indicate that the invitation is for an administrator account.
     *
     * There is no matching `forMember()` state: `member` is the factory default and the column
     * default, so a second state saying so would be a second place to keep in step.
     */
    public function forAdministrator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Indicate that the invitation has already been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the invitation's link has expired.
     *
     * The expiry is set a day beyond the window rather than a second past `now()` so the row stays
     * unambiguously stale however a test travels the clock.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDays(Invitation::EXPIRES_AFTER_DAYS + 1),
        ]);
    }

    /**
     * Indicate that the invitation is attached to no administrator.
     *
     * This is the state a row reaches when the administrator who issued it is deleted, because
     * `invited_by_id` is `nullOnDelete`.
     */
    public function withoutInviter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_by_id' => null,
        ]);
    }
}
