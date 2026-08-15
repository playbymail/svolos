<?php

namespace App\Http\Controllers\Player;

use App\Concerns\PresentsGeneration;
use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Player\GameProfileUpdateRequest;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Playing one game from the inside: the screen a player gets for a game they hold a seat at.
 *
 * The counterpart of `Gamemaster\GameController::show()` and shaped by the opposite question. That
 * screen answers "what is this world, and who is in it"; this one answers "what is my empire, and
 * what can I see from it". Access is decided by `App\Http\Middleware\EnsureUserIsPlayer`, which reads
 * an active `GameRole::Player` seat at the game in the URL and reads nothing else — a gamemaster and
 * a seatless administrator are both refused, and the middleware says why.
 *
 * ## A player is not shown the cluster
 *
 * `PresentsGeneration::presentLocations()` is **omniscient**: all hundred systems, every home, and
 * every player's account name. It is the review surface for a world being generated, and using it
 * here would hand a player the finished map on their first visit. This controller shapes the seat's
 * **own** home instead, one location, in the same array shape so the map component needs no changes.
 *
 * The rest of the cluster is not withheld forever — it is withheld until it has been explored, and
 * there is nothing in the schema yet that records what a player has seen. When there is, it belongs
 * in `presentHomeLocation()`'s place rather than in a filter bolted onto the omniscient version: the
 * two questions have different answers and should not share a method.
 *
 * `presentLocationDetail()` *is* reused, unchanged, and it is the one thing this class takes from that
 * trait. A player asks it only about their own home, where every entity standing there is their own.
 * The day anything in this game moves, the `player_name` it puts on each entity needs gating here.
 */
class GameController extends Controller
{
    use PresentsGeneration;

    /**
     * Show the player their game and their empire.
     *
     * **The map and the probe report are withheld until the game is active.** A game in setup is still
     * being built — its world can be thrown away and generated again, so anything shown from it is
     * provisional — and a game that has been archived is over. What a player can always do is name
     * their empire, which is why the profile is not behind the same condition: the sensible moment to
     * choose a name is before the game starts.
     *
     * `homeSystem` does not ride on `Inertia::optional()` the way the gamemaster's `locationDetail`
     * does. That prop is deferred because a gamemaster reviewing a cluster would otherwise be sent
     * several hundred planets to look at one system; here there is exactly one system and the screen
     * always wants it, so deferring it would cost a round trip to save nothing.
     */
    public function show(Request $request, Game $game): Response
    {
        $seat = $this->seatFor($request, $game);

        $home = $game->status === GameStatus::Active
            ? $this->homeLocation($game, $seat)
            : null;

        return Inertia::render('player/games/Show', [
            'game' => $this->present($game),
            'seat' => $this->presentSeat($seat),
            /*
             * A list of one, or of none. The map component takes an array and marks what is in it, so
             * a player who has not been placed gets the empty grid and its centre hex rather than a
             * special case — and there is no separate `home` prop, because everything one would carry
             * is already on this row and two copies of a location are two things to keep in step.
             */
            'locations' => $home === null ? [] : [$this->presentHomeLocation($home, $seat)],
            'homeSystem' => $home === null ? null : $this->presentLocationDetail($game, $home->id),
        ]);
    }

    /**
     * Save what this player has decided about their empire.
     *
     * The seat is resolved from the session rather than from the URL, which is what makes a scoped
     * binding unnecessary here: there is no seat id to mistype, and a player can only ever address
     * their own. `fill($request->validated())` is the same line the other areas write, and it is safe
     * for the same two reasons — `GameProfileUpdateRequest` validates two fields, and `number` is out
     * of `GameSeat`'s `#[Fillable]` besides.
     */
    public function updateProfile(GameProfileUpdateRequest $request, Game $game): RedirectResponse
    {
        $seat = $this->seatFor($request, $game);

        $seat->fill($request->validated());
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':empire is ready.', ['empire' => $seat->empireName()]),
        ]);

        return to_route('games.show', ['game' => $game]);
    }

    /**
     * Get the viewer's own seat at this game.
     *
     * `EnsureUserIsPlayer` has already established that this row exists, so `firstOrFail()` is a
     * restatement rather than a check — but it is the restatement that keeps this method fail-closed
     * if the route is ever mounted without the gate, which is worth more than the `null` a `first()`
     * would hand to everything downstream.
     *
     * The game is attached to the seat rather than left to be lazy-loaded, because `defaultEmpireName()`
     * reads `$seat->game->short_name` and the game is already in hand.
     */
    private function seatFor(Request $request, Game $game): GameSeat
    {
        $seat = $game->activeSeats()
            ->where('user_id', $this->authenticatedUser($request)->getKey())
            ->where('role', GameRole::Player)
            ->firstOrFail();

        $seat->setRelation('game', $game);

        return $seat;
    }

    /**
     * Find the system this seat begins at, with the counts the map marks it by.
     *
     * Null when the home stellia stage has not placed this player, which for an active game means
     * they were seated after the arrangement was drawn — see `Game::playersWithoutHomeStellium()`.
     * `whereKey(null)` matches no row, so an unplaced seat falls out of the query rather than being
     * checked for separately.
     */
    private function homeLocation(Game $game, GameSeat $seat): ?Location
    {
        return $game->locations()
            ->whereKey($seat->homeStellium?->location_id)
            ->with(['stellium' => fn ($query) => $query->withCount(['stars', 'planets'])])
            ->first();
    }

    /**
     * Shape the player's home in the array shape the cluster map reads.
     *
     * Deliberately identical to one row of `PresentsGeneration::presentLocations()`, so
     * `ClusterHexMap` needs no player-specific branch. Two of the fields differ in meaning even so:
     *
     * - **neither count is ever null here.** On the gamemaster's screen null means "that stage has not
     *   run yet", a state this screen cannot be in — a game only becomes active once every stage has
     *   been accepted, which `GameValidationRules::gameStatusRules()` enforces;
     * - **`home_player_name` is the empire name**, from `GameSeat::empireName()` — the same rule
     *   `PresentsGeneration::empireNameFor()` applies on every other screen that names an empire, so
     *   the map and the probe report beneath it never disagree about what to call one.
     *
     * @return array{
     *     id: int,
     *     ordinal: int,
     *     x: int,
     *     y: int,
     *     z: int,
     *     radius: float,
     *     star_count: int|null,
     *     planet_count: int|null,
     *     home_seat_id: int|null,
     *     home_player_name: string|null,
     * }
     */
    private function presentHomeLocation(Location $location, GameSeat $seat): array
    {
        return [
            'id' => $location->id,
            'ordinal' => $location->ordinal,
            'x' => $location->x,
            'y' => $location->y,
            'z' => $location->z,
            'radius' => round($location->radius(), 2),
            'star_count' => $location->stellium?->stars_count,
            'planet_count' => $location->stellium?->planets_count,
            'home_seat_id' => $seat->id,
            'home_player_name' => $seat->empireName(),
        ];
    }

    /**
     * Shape the game for its player's screen.
     *
     * Much less than the gamemaster gets, and the omissions are the point: no seed, because the number
     * a world was drawn from is not a player's to know while they are exploring it; no roster, because
     * who else is playing is the game's to reveal; no generation state, because a player meets a
     * finished world or none.
     *
     * `year` and `quarter` are derived from `turn` by the model and sent alongside it rather than
     * being worked out on the screen, so there is one implementation of the calendar and it is the one
     * with a test around it.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     short_name: string,
     *     status: string,
     *     status_label: string,
     *     is_active: bool,
     *     turn: int,
     *     year: int,
     *     quarter: int,
     * }
     */
    private function present(Game $game): array
    {
        return [
            'id' => $game->id,
            'name' => $game->name,
            'short_name' => $game->short_name,
            'status' => $game->status->value,
            'status_label' => $game->status->label(),
            'is_active' => $game->status === GameStatus::Active,
            'turn' => $game->turn,
            ...$game->yearAndQuarter(),
        ];
    }

    /**
     * Shape the player's own seat: their empire.
     *
     * `empire_name` is the raw column and `empire_name_default` is what the empire is called without
     * one, and **both** are sent because the screen needs to tell the two apart: it prefills its input
     * with the default while still being able to say the empire has not been named yet. Collapsing
     * them into one resolved string would make an unnamed empire indistinguishable from one somebody
     * deliberately named "Game ACME Seat 3".
     *
     * @return array{
     *     id: int,
     *     number: int,
     *     empire_name: string|null,
     *     empire_name_default: string,
     *     email_notifications: bool,
     * }
     */
    private function presentSeat(GameSeat $seat): array
    {
        return [
            'id' => $seat->id,
            'number' => $seat->number,
            'empire_name' => $seat->empire_name,
            'empire_name_default' => $seat->defaultEmpireName(),
            'email_notifications' => $seat->email_notifications,
        ];
    }
}
