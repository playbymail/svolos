<?php

namespace App\Actions\Invitations;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;

/**
 * Mints an invitation token and emails the link. The only place either happens.
 *
 * Both the create and the resend endpoint go through here, which is what keeps the security
 * properties of the token in one readable place:
 *
 * - the plain text exists only as a local in `handle()` and inside the notification it is handed to;
 *   the column gets `Invitation::hashToken()` of it and nothing else;
 * - **resending therefore rotates the token.** The old hash is overwritten, so the previously
 *   emailed link stops working. That is not a side effect to be worked around — it is the only
 *   behaviour available once the plain text is unrecoverable, and it means a link forwarded to the
 *   wrong person can be revoked by resending.
 *
 * The row is upserted on the email address rather than inserted, so re-inviting somebody reuses
 * their invitation instead of leaving a second live link behind, and `accepted_at` is cleared: a new
 * link is a new offer, and an invitation still marked accepted would render as "already used".
 */
class IssueInvitation
{
    /**
     * Issue (or reissue) an invitation for an email address and email the link.
     */
    public function handle(string $email, UserRole $role, ?User $invitedBy = null): Invitation
    {
        $token = Invitation::generateToken();

        /*
         * Attributes are assigned one at a time rather than mass-assigned, and the model declares no
         * `#[Fillable]` at all. `token` and `invited_by_id` must never be able to arrive from
         * request input, and spelling the writes out is what guarantees it: `Invitation::create()`
         * or `updateOrCreate()` with a request array would throw rather than quietly trust it.
         */
        $invitation = Invitation::query()->firstWhere('email', $email) ?? new Invitation;

        $invitation->email = $email;
        $invitation->token = Invitation::hashToken($token);
        $invitation->role = $role;
        $invitation->invited_by_id = $invitedBy?->id;
        $invitation->expires_at = now()->addDays(Invitation::EXPIRES_AFTER_DAYS);
        $invitation->accepted_at = null;
        $invitation->save();

        $invitation->notify(new InvitationNotification($token));

        return $invitation;
    }
}
