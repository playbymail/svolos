<?php

namespace App\Enums;

/**
 * Why an invitation link cannot be used.
 *
 * The three cases are kept apart all the way to the screen on purpose. They have three different
 * remedies — check the link, ask for a new invitation, or simply log in — and a single "invalid
 * invitation" page would leave somebody who has already used their invitation with no idea that
 * their account exists.
 *
 * Telling an unauthenticated visitor which of the three applies leaks nothing worth having: the
 * token is unguessable, so anybody holding one either was sent it or already has it, and knowing
 * that a token they hold is expired rather than unknown gets them no closer to a valid one.
 */
enum InvitationLinkProblem: string
{
    case Unknown = 'unknown';
    case Expired = 'expired';
    case Accepted = 'accepted';

    /**
     * Get the heading shown for this problem.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'This invitation link is not valid',
            self::Expired => 'This invitation link has expired',
            self::Accepted => 'This invitation has already been used',
        };
    }

    /**
     * Get the explanation shown underneath the heading.
     */
    public function description(): string
    {
        return match ($this) {
            self::Unknown => 'We could not find an invitation for this link. It may have been mistyped, cut short by an email client, or withdrawn by an administrator.',
            self::Expired => 'Invitation links are only good for a limited time. Ask the person who invited you to send a new one.',
            self::Accepted => 'An account has already been created from this invitation. Log in with the email address it was sent to, or reset your password if you have forgotten it.',
        };
    }
}
