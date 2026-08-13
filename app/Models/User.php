<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * An application user account.
 *
 * `role` is deliberately **absent** from `#[Fillable]` and must stay absent. It is the boundary
 * between a member and an administrator, and everything that creates or updates an account does so
 * from request input: `ProfileController::update()` calls `fill($request->validated())`, and
 * invitation acceptance goes through `App\Actions\Fortify\CreateNewUser`. If `role` were fillable,
 * any of those posting `role=admin` would promote the account. The few places allowed to set it —
 * `app:create-admin` — assign it explicitly with `$user->role = UserRole::Admin`.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property bool $is_agent
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $sessions_count
 * @property-read Collection<int, Session> $sessions
 * @property-read Collection<int, GameSeat> $gameSeats
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default attribute values.
     *
     * The `role` default repeats the column default from
     * `database/migrations/..._add_role_to_users_table.php` so an unsaved `new User` already reads
     * back as a member instead of hitting the enum cast with a null. `is_agent` is here for the same
     * reason: without it `isAgent()` returns null out of a `bool` return type on an unsaved model.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Member->value,
        'is_agent' => false,
    ];

    /**
     * Determine whether the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Determine whether this account is driven by software rather than by a person.
     *
     * An agent account holds seats and plays the game like anybody else; what differs is how it
     * authenticates. It has no usable password and no mailbox, and the four places a human could
     * otherwise reach it — the login attempt, a password reset, impersonation, and a role change —
     * all read this method. See `.ai/rules/agents.md`.
     */
    public function isAgent(): bool
    {
        return $this->is_agent;
    }

    /**
     * Send the password reset notification, unless this is an agent account.
     *
     * Returning early is the reset half of the isolation described on `isAgent()`. Agent addresses
     * are on the non-routable `.invalid` domain, so nothing would arrive anyway — but "the mailbox
     * does not exist" is a happy accident rather than a control, and a reset that completed would
     * hand a person a working password for an account whose orders the engine attributes to an agent.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        if ($this->isAgent()) {
            return;
        }

        parent::sendPasswordResetNotification($token);
    }

    /**
     * Get the signed-in browsers this account currently has.
     *
     * `sessions.user_id` carries **no** foreign key (see `App\Models\Session`), so this relation is
     * matched on the column alone and nothing cascades: deleting an account leaves its session rows
     * behind unless they are deleted explicitly, which
     * `App\Http\Controllers\Admin\UserController::destroy()` does. Passkeys are the contrast — that
     * table does have `cascadeOnDelete`, so they need no help.
     *
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * Get every seat this account holds at a game, retired ones included.
     *
     * Named `gameSeats` rather than `seats` on purpose: a bare `seats` on an account reads as
     * furniture rather than as places at games, and this side of the relation is the member-facing
     * one — `App\Http\Controllers\DashboardController` is what it exists for.
     *
     * It stays **unfiltered** for the same reason `Game::seats()` does: a retired seat is still the
     * row that occupies this account's place in the unique index on `(game_id, user_id)`, so a
     * caller asking "has this account ever had a seat here?" must be able to see it. Callers that
     * want the live roster filter on `is_active` themselves — the dashboard does.
     *
     * `game_seats.user_id` cascades on delete, so deleting an account takes these rows with it.
     *
     * @return HasMany<GameSeat, $this>
     */
    public function gameSeats(): HasMany
    {
        return $this->hasMany(GameSeat::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_agent' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
