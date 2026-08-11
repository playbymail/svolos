<?php

namespace App\Http\Controllers;

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSeat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a signed-in account sees of itself: the games it holds a seat in, split by the game role it
 * holds there.
 *
 * This screen is deliberately **role-blind** in the application sense. An administrator lands here
 * too — it is where `ImpersonationController::store()` sends a session that has just become somebody
 * else — and gets exactly what any other account gets: their own seats, or the blurb if they have
 * none. Holding `App\Enums\UserRole::Admin` says nothing about seats, so there is nothing to branch
 * on. The only role read anywhere here is `App\Enums\GameRole`, which is scoped to one game and
 * carries no application permissions at all (see `.ai/rules/roles.md`).
 */
class DashboardController extends Controller
{
    /**
     * Show the signed-in account's games.
     *
     * Only **active** seats count: a retired seat means the account is out of that game, and this
     * screen answers "what am I in?" rather than "what was I ever in?" — the history belongs to the
     * administrator's roster, which is the screen that can reactivate a seat.
     *
     * A section with no seats is **absent from the props entirely** rather than present and empty,
     * so the page has nothing to decide: a key that is there is a heading to render. That is not the
     * same state as a section whose games are all archived, which is present, keeps its heading and
     * its toggle, and says in words that everything in it is put away.
     *
     * One query for the seats and one more for their games (`with('game')`), whatever the roster
     * size. Ordering is done on the loaded collection rather than in SQL because ordering a
     * `game_seats` query by `games.short_name` would need a join purely to sort two handfuls of rows.
     */
    public function __invoke(Request $request): Response
    {
        $seats = $this->authenticatedUser($request)
            ->gameSeats()
            ->where('is_active', true)
            ->with('game')
            ->get()
            ->sortBy(fn (GameSeat $seat): string => $seat->game->short_name)
            ->values();

        $props = [];

        foreach (['gamemasterGames' => GameRole::Gamemaster, 'playerGames' => GameRole::Player] as $prop => $role) {
            $games = $this->gamesFor($seats, $role);

            if ($games !== []) {
                $props[$prop] = $games;
            }
        }

        return Inertia::render('Dashboard', $props);
    }

    /**
     * Pull the games held under one game role out of the already-loaded seats.
     *
     * @param  Collection<int, GameSeat>  $seats
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     short_name: string,
     *     status: string,
     *     status_label: string,
     *     is_archived: bool,
     * }>
     */
    private function gamesFor(Collection $seats, GameRole $role): array
    {
        return $seats
            ->filter(fn (GameSeat $seat): bool => $seat->role === $role)
            ->map(fn (GameSeat $seat): array => $this->present($seat->game))
            ->values()
            ->all();
    }

    /**
     * Shape one game for the dashboard.
     *
     * **Archived games are in the payload, flagged rather than filtered.** They are hidden by
     * default, but the toggle that reveals them is client-side state in `Dashboard.svelte`, so the
     * rows have to be there already: a query parameter or a partial reload would make showing a game
     * somebody is still in cost a round trip, and would make the "everything here is archived" state
     * indistinguishable from an empty section on the server's side of the wire. The count of seats
     * is deliberately not presented — this screen is about which games the account is in, and the
     * roster of each is the administrator's screen.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     short_name: string,
     *     status: string,
     *     status_label: string,
     *     is_archived: bool,
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
            'is_archived' => $game->status === GameStatus::Archived,
        ];
    }
}
