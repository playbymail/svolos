<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The emailed invitation, and the only place the plain-text token ever appears.
 *
 * The token is passed in rather than read off the invitation because the invitation does not have
 * it: `invitations.token` stores a sha256 hash, and `App\Actions\Invitations\IssueInvitation` hands
 * the plain text to this notification and then lets it go. If this class ever starts reading
 * `$invitation->token`, the link it builds will be the hash and nothing will be able to accept it.
 *
 * It is deliberately not queued. Invitations are sent one at a time by a human who is watching, the
 * default queue connection is `database`, and a queued invitation on a host with no worker running
 * is an invitation that silently never arrives.
 */
class InvitationNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(private readonly string $token) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $expiresInDays = Invitation::EXPIRES_AFTER_DAYS;

        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => config('app.name')]))
            ->greeting(__('You have been invited'))
            ->line(__('An administrator has invited you to create an account on :app.', ['app' => config('app.name')]))
            ->action(__('Accept invitation'), route('invitations.show', ['token' => $this->token]))
            ->line(__('This invitation link expires in :days days and can only be used once.', ['days' => $expiresInDays]))
            ->line(__('If you were not expecting this invitation, you can ignore this email.'));
    }
}
