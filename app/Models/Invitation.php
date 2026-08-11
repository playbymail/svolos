<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * An invitation to create an account, and the only way an account comes into existence.
 *
 * **`token` holds a sha256 hash, never the token itself.** The plain text is generated once in
 * `App\Actions\Invitations\IssueInvitation`, handed straight to the notification, and then dropped:
 * only the emailed link ever carries it. A dump of this table therefore yields no usable invitation
 * link, in the same way a dump of `users` yields no usable password.
 *
 * The consequence to know before touching anything here: a token cannot be recovered. Resending an
 * invitation issues a **new** token, which rewrites this column and kills the previously emailed
 * link. See `.ai/rules/auth.md`.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property UserRole $role
 * @property int|null $invited_by_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $invitedBy
 */
#[Hidden(['token'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, Notifiable;

    /**
     * The number of days an invitation link stays usable for.
     */
    public const int EXPIRES_AFTER_DAYS = 7;

    /**
     * Generate a fresh plain-text invitation token.
     *
     * The caller is responsible for emailing this and storing only `hashToken()` of it. 64 random
     * characters is far past guessing range, which is what lets the acceptance route be reachable
     * by a guest with nothing but the token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Hash a plain-text invitation token into the value stored in the `token` column.
     *
     * Deliberately a plain unsalted sha256 rather than `Hash::make()`: the column has to be
     * *searchable*, because an acceptance request arrives with the token and nothing else, and a
     * per-row salt would force a scan of every invitation. A password hash's slowness buys nothing
     * here either — the input is 64 random characters from our own generator, not a human-chosen
     * secret, so there is no dictionary to run against it.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Determine whether this invitation has already been used to create an account.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Determine whether this invitation's link is past its expiry.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Determine whether this invitation can still be accepted.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Get the derived status of this invitation.
     *
     * Accepted beats expired: an invitation that was used and has since passed its expiry was still
     * used, and saying "expired" about an account that exists would be a lie.
     */
    public function status(): InvitationStatus
    {
        return match (true) {
            $this->isAccepted() => InvitationStatus::Accepted,
            $this->isExpired() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /**
     * Scope the query to invitations that can still be accepted.
     *
     * @param  Builder<Invitation>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * Get the administrator who issued the live link for this invitation.
     *
     * Nullable, and not only because the column is: `nullOnDelete` means deleting an administrator
     * leaves their invitations standing with nobody attached, which is the right outcome for an
     * invitation that has already been emailed.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
