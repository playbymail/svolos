<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GameStoreRequest;
use App\Http\Requests\Admin\GameUpdateRequest;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's view of the game roster: which games exist, and who sits at each of them.
 *
 * Every route on this screen is an `/admin` route gated by `App\Enums\UserRole::Admin`. **Holding a
 * gamemaster seat grants access to none of it.** The two role systems are unrelated by design — see
 * `App\Enums\GameRole` and `.ai/rules/roles.md` — so nothing here consults a seat to decide whether the
 * request is allowed, and nothing here treats `users.role` as a game role.
 */
class GameController extends Controller
{
    /**
     * List every game with its roster size and where it is in its life.
     *
     * Archived games are **not** filtered out: this is the administrator's inventory, and a game that has
     * been put away still has to be findable to be brought back. `unarchived_count` is what the heading
     * uses to say how much of the list is still in play.
     *
     * The two seat counts come from `withCount(['seats', 'activeSeats'])`, so a page listing a hundred
     * games issues one query for each count rather than one per row. `activeSeats` is a real relation on
     * `Game` rather than a `withCount` closure alias, so the `active_seats_count` this reads back is
     * named after something that exists — see the note on `Game::activeSeats()`.
     */
    public function index(): Response
    {
        $games = Game::query()
            ->withCount(['seats', 'activeSeats'])
            ->orderBy('name')
            ->get()
            ->map(fn (Game $game): array => $this->present($game))
            ->values()
            ->all();

        return Inertia::render('admin/games/Index', [
            'games' => $games,
            'unarchivedCount' => Game::query()->unarchived()->count(),
        ]);
    }

    /**
     * Create a game.
     *
     * A new game always lands in `setup` with no seats, which is why `GameStoreRequest` does not accept a
     * status: there is nothing to choose yet. The redirect goes to the new game rather than back to the
     * list because a game with an empty roster is not finished — the next thing to do is add seats.
     */
    public function store(GameStoreRequest $request): RedirectResponse
    {
        $game = new Game;
        $game->fill($request->validated());
        $game->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was created.', ['name' => $game->name]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }

    /**
     * Show one game's metadata and its seat roster.
     *
     * Retired seats are listed alongside active ones rather than hidden. They are the only record that an
     * account was ever in the game, and reactivating one is how a departed account comes back — a roster
     * that hid them would make that look impossible while still refusing to add a second seat.
     */
    public function show(Game $game): Response
    {
        $game->loadCount(['seats', 'activeSeats']);

        $seats = $game->seats()
            ->with('user')
            ->get()
            ->sortBy([
                /* Active seats first, then alphabetically, so the live roster reads as one block. */
                fn (GameSeat $seat): int => $seat->is_active ? 0 : 1,
                fn (GameSeat $seat): string => $seat->user->name,
            ])
            ->map(fn (GameSeat $seat): array => $this->presentSeat($seat))
            ->values()
            ->all();

        return Inertia::render('admin/games/Show', [
            'game' => $this->present($game),
            'seats' => $seats,
            'assignableAccounts' => $this->assignableAccounts($game),
            'roles' => array_map(
                fn (GameRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                GameRole::cases(),
            ),
            'statuses' => array_map(
                fn (GameStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                GameStatus::cases(),
            ),
        ]);
    }

    /**
     * Change a game's name, short name or status.
     *
     * The short name is uppercased by `GameUpdateRequest` before the character rules see it, so editing a
     * game folds its short name exactly as creating one does.
     */
    public function update(GameUpdateRequest $request, Game $game): RedirectResponse
    {
        $game->fill($request->validated());
        $game->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was updated.', ['name' => $game->name]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }

    /**
     * Delete a game, and with it every seat at it.
     *
     * `game_seats.game_id` cascades, so the seats need no help here — which is exactly why the
     * confirmation on the list names how many are about to go. Deleting a game is the one operation that
     * *does* destroy seats, and it is the reason there is no seat destroy endpoint: an administrator who
     * wants somebody out of a game retires their seat, and one who wants the game gone does this.
     */
    public function destroy(Game $game): RedirectResponse
    {
        $game->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was deleted.', ['name' => $game->name]),
        ]);

        return to_route('admin.games.index');
    }

    /**
     * List the accounts that could still be given a seat at this game.
     *
     * **Every account that already holds a seat is excluded, retired ones included.** A retired seat still
     * occupies its account's place in the unique index on `(game_id, user_id)`, so offering that account
     * again could only ever produce the "already has a seat" rejection; the way back in is to reactivate
     * the seat that is already on the roster. `GameSeatStoreRequest` enforces the same thing for a
     * hand-made post.
     *
     * The exclusion is a subquery on `game_seats` rather than a relation on `User`: a user-side seats
     * relation belongs to the member-facing screens, and there is nothing this list needs that the
     * subquery does not give it.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function assignableAccounts(Game $game): array
    {
        return User::query()
            ->whereNotIn(
                'id',
                GameSeat::query()->select('user_id')->where('game_id', $game->id),
            )
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();
    }

    /**
     * Shape one game for the list and for its own screen.
     *
     * `seats_count` and `active_seats_count` are presented separately rather than as one number: the
     * difference between them is how many people have left, which is a fact about the game an
     * administrator wants without opening it.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     short_name: string,
     *     status: string,
     *     status_label: string,
     *     seats_count: int,
     *     active_seats_count: int,
     *     created_at: string,
     *     created_at_diff: string|null,
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
            'seats_count' => $game->seats_count ?? 0,
            'active_seats_count' => $game->active_seats_count ?? 0,
            'created_at' => $game->created_at?->toDayDateTimeString() ?? '',
            'created_at_diff' => $game->created_at?->diffForHumans(),
        ];
    }

    /**
     * Shape one seat for the roster.
     *
     * @return array{
     *     id: int,
     *     user_id: int,
     *     user_name: string,
     *     user_email: string,
     *     role: string,
     *     role_label: string,
     *     is_active: bool,
     *     created_at_diff: string|null,
     * }
     */
    private function presentSeat(GameSeat $seat): array
    {
        return [
            'id' => $seat->id,
            'user_id' => $seat->user_id,
            'user_name' => $seat->user->name,
            'user_email' => $seat->user->email,
            'role' => $seat->role->value,
            'role_label' => $seat->role->label(),
            'is_active' => $seat->is_active,
            'created_at_diff' => $seat->created_at?->diffForHumans(),
        ];
    }
}
