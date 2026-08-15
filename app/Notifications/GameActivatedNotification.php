<?php

namespace App\Notifications;

use App\Models\Game;
use App\Models\GameSeat;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email a player gets when the game they are in starts.
 *
 * The seat rides along beside the game because this is addressed to an *empire* rather than to an
 * account: the recipient may be in several games, and "your game is active" answers nothing unless it
 * says which empire, at which game. Everything the message names — the empire, its number, the turn —
 * is on those two models already, so nothing is passed in that could disagree with them.
 *
 * It is deliberately not queued, for the reason `InvitationNotification` is not. A game has a handful
 * of seats and activating one is a single act by a gamemaster who is watching the screen; the default
 * queue connection is `database`, and a queued notification on a host with no worker running is a
 * notification that silently never arrives. The moment a fan-out here is large enough to be worth
 * queueing — a message to every player of every game, say — that is a different notification with a
 * different failure mode, and it should be given `ShouldQueue` and a worker rather than this one
 * being quietly changed underneath a gamemaster who has no way to tell.
 */
class GameActivatedNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly Game $game,
        private readonly GameSeat $seat,
    ) {}

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
     *
     * The link goes to the player's own screen for the game rather than to the dashboard, because
     * that screen is both the thing this email is about and the only place the notification can be
     * turned off again — which the last line says, so that unsubscribing never requires guessing.
     */
    public function toMail(object $notifiable): MailMessage
    {
        ['year' => $year, 'quarter' => $quarter] = $this->game->yearAndQuarter();

        return (new MailMessage)
            ->subject(__(':game has begun', ['game' => $this->game->name]))
            ->greeting(__(':game has begun', ['game' => $this->game->name]))
            ->line(__('The gamemaster has made :game active. You are playing :empire, empire number :number.', [
                'game' => $this->game->name,
                'empire' => $this->seat->empireName(),
                'number' => $this->seat->number,
            ]))
            ->line(__('The game stands at turn :turn — year :year, quarter :quarter.', [
                'turn' => $this->game->turn,
                'year' => $year,
                'quarter' => $quarter,
            ]))
            ->action(__('Open :game', ['game' => $this->game->short_name]), route('games.show', ['game' => $this->game]))
            ->line(__('You are getting this because you asked to be emailed about this game. You can turn that off on the same screen.'));
    }
}
