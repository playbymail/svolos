<?php

namespace App\Actions\Games;

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Notifications\GameActivatedNotification;

/**
 * Tells the players that their game has started. The only place that happens.
 *
 * Both status endpoints — the gamemaster's and the administrator's — call this immediately after
 * saving, and it is one line at each call site because **the whole rule lives here**. Two controllers
 * each holding half of "has it just become active, and who wants to hear about it" is two copies to
 * keep in step, and the copies would drift the moment one of them grew a second reason to save.
 *
 * ## `wasChanged()` is the rule, and it has to be read after the save
 *
 * A status form posts the status it is showing, so saving a game that is already active is the
 * ordinary result of somebody pressing the button twice, or of changing something else on the
 * administrator's form. Announcing on `status === Active` alone would mail everybody again each time.
 * `wasChanged()` answers what the save actually did, which is the question — and it answers it
 * correctly for the case that matters most, a game returning to `Active` from `Paused`, which is a
 * genuine restart and should be announced.
 *
 * A model event on `Game` would put the check nearer the write and is deliberately not used: it would
 * fire inside seeders, factories and tests that have no interest in mail, and a notification sent as
 * an invisible consequence of `save()` is the kind of thing that is discovered in a production
 * mailbox.
 *
 * ## Who is written to
 *
 * Active player seats that have opted in, and nobody else. Gamemasters are excluded because they are
 * the ones who pressed the button; retired seats because they are out of the game; and everyone else
 * because `game_seats.email_notifications` defaults to false and only the seat's own holder can turn
 * it on, from `Player\GameController::updateProfile()`.
 *
 * Agent accounts need no exclusion of their own, and adding one would be a rule nobody asked for:
 * an agent cannot sign in, so its seat's opt-in can never be turned on, so an agent is never in this
 * set. Agents learn a game is active by asking the API.
 */
class AnnounceGameActivation
{
    /**
     * Announce the game, if the save that just happened is what made it active.
     *
     * Returns the number of players written to, which the tests assert against and a caller may
     * ignore. Zero is an ordinary answer twice over: the game did not just become active, or nobody
     * asked to hear about it.
     */
    public function handle(Game $game): int
    {
        if (! $game->wasChanged('status') || $game->status !== GameStatus::Active) {
            return 0;
        }

        $seats = $game->activeSeats()
            ->where('role', GameRole::Player)
            ->where('email_notifications', true)
            ->with('user')
            ->get();

        foreach ($seats as $seat) {
            /*
             * The game is attached rather than lazy-loaded: the notification asks the seat for its
             * empire name, which falls back to one built from the game's short name.
             */
            $seat->setRelation('game', $game);

            $seat->user->notify(new GameActivatedNotification($game, $seat));
        }

        return $seats->count();
    }
}
