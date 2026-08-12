<?php

namespace App\Http\Controllers\Gamemaster;

use App\Concerns\PresentsGeneration;
use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamemaster\GameSeedUpdateRequest;
use App\Http\Requests\Gamemaster\GameStatusUpdateRequest;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Running one game from the inside: the screen a gamemaster gets for a game they hold a seat at.
 *
 * It is the member-facing counterpart of `Admin\GameController::show()` and carries the same
 * roster tools, but it is **not** an `/admin` route and must never become one. Access is decided by
 * `App\Http\Middleware\EnsureUserIsGamemaster`, which reads an active `GameRole::Gamemaster` seat at
 * the game in the URL and reads nothing else — no `users.role`, no `isAdmin()`. An administrator with
 * no seat is refused here and uses `/admin/games/{game}` instead; the two role systems are unrelated
 * and an authorisation check reads exactly one of them (see `.ai/rules/roles.md`).
 *
 * ## Three things a gamemaster may not do, and where each is enforced
 *
 * - **Rename the game.** `GameStatusUpdateRequest` validates the status alone, so `validated()` has
 *   no `name` or `short_name` to fill. The short name in particular leaves the application, in turn
 *   reports and generated file names, so it is the administrator's to set.
 * - **Retire themselves.** `Gamemaster\GameSeatController::retire()` refuses their own seat.
 * - **Demote a gamemaster to a player**, or **change the role on a retired seat.** Both are refused
 *   by `Gamemaster\GameSeatController::updateRole()`.
 *
 * The last two are re-stated in the payload as `can_retire` and `can_change_role` so the screen does
 * not render controls that would 403, but the server is the boundary — the flags are presentation.
 *
 * The game's **seed** is not a fourth exception: a gamemaster may see it and may set it, on exactly
 * the same terms an administrator can — while the game is still in `GameStatus::Setup`. That limit is
 * about the game, not about who is asking, so it is a validation rule shared by both areas rather than
 * a refusal that belongs to this one. See `updateSeed()` below.
 */
class GameController extends Controller
{
    use PresentsGeneration;

    /**
     * Show the game and its roster.
     *
     * The same shape as the administrator's screen, plus the three per-seat flags that say what this
     * particular gamemaster may do to each row. Retired seats are listed alongside active ones, since
     * reactivating one is how a departed account comes back and a roster that hid them would make
     * that look impossible while still refusing to add a second seat.
     */
    public function show(Request $request, Game $game): Response
    {
        $viewer = $this->authenticatedUser($request);

        $game->loadCount(['seats', 'activeSeats']);

        $seats = $game->seats()
            ->with('user')
            ->get()
            ->sortBy([
                /* Active seats first, then alphabetically, so the live roster reads as one block. */
                fn (GameSeat $seat): int => $seat->is_active ? 0 : 1,
                fn (GameSeat $seat): string => $seat->user->name,
            ])
            ->map(fn (GameSeat $seat): array => $this->presentSeat($seat, $viewer))
            ->values()
            ->all();

        return Inertia::render('gamemaster/games/Show', [
            'game' => $this->present($game),
            'generation' => $this->presentGeneration($game, withSuggestions: true),
            /*
             * The whole cluster ships with the page: a hundred locations of four small numbers each is
             * a smaller payload than the request that would fetch them, and reviewing a cluster means
             * looking at all of it. It is empty until a cluster run exists.
             */
            'locations' => $this->presentLocations($game),
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
     * Move the game to another status.
     *
     * `fill($request->validated())` is deliberately the same line the administrator's controller
     * uses. What differs is the request: `GameStatusUpdateRequest` validates `status` alone, so
     * `validated()` can only ever contain that one key and a posted `name` or `short_name` is
     * dropped on the floor rather than written. Do not "improve" this into a `fill($request->all())`
     * with an `only()` somewhere, and do not add the two fields to that request.
     */
    public function update(GameStatusUpdateRequest $request, Game $game): RedirectResponse
    {
        $game->fill($request->validated());
        $game->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now :status.', [
                'name' => $game->name,
                'status' => mb_strtolower($game->status->label()),
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Change the number this game's randomness is drawn from.
     *
     * A gamemaster may do this and it is **not** a fourth exception to the rules above: a game in
     * `GameStatus::Setup` has not been played yet, so there is no run for a new seed to rewrite. The
     * condition is about the game rather than about who is asking, which is why it is a rejected field
     * rather than a 403 and why `Gamemaster\GameSeedUpdateRequest` shares its rule with the
     * administrator's copy through `App\Concerns\GameValidationRules`. Once the game leaves setup, this
     * endpoint refuses an administrator exactly as it refuses a gamemaster.
     *
     * Assigned explicitly rather than filled, because `seed` is out of `Game`'s `#[Fillable]`.
     */
    public function updateSeed(GameSeedUpdateRequest $request, Game $game): RedirectResponse
    {
        $game->seed = (int) $request->validated('seed');
        $game->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now seeded with :seed.', [
                'name' => $game->name,
                'seed' => $game->seed,
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * List the accounts that could still be given a seat at this game.
     *
     * Identical to the administrator's list, and excluding every account that already holds a seat —
     * **retired ones included** — for the same reason: a retired seat still occupies its account's
     * place in the unique index on `(game_id, user_id)`, so offering that account again could only
     * ever produce the "already has a seat" rejection. The way back in is to reactivate the row
     * already on the roster.
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
     * Shape the game for its gamemaster's screen.
     *
     * `name` and `short_name` are here to be **displayed**, not edited — the screen renders them as
     * text rather than as inputs, and `update()` cannot write them whatever the screen does. The seed
     * is the opposite case and worth not confusing with them: a gamemaster sees it *and* may set it,
     * for as long as the game is in setup, which is what `can_change_seed` says.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     short_name: string,
     *     seed: int,
     *     can_change_seed: bool,
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
            'seed' => $game->seed,
            'can_change_seed' => $game->status === GameStatus::Setup && ! $game->hasGenerationRuns(),
            'status' => $game->status->value,
            'status_label' => $game->status->label(),
            'seats_count' => $game->seats_count ?? 0,
            'active_seats_count' => $game->active_seats_count ?? 0,
            'created_at' => $game->created_at?->toDayDateTimeString() ?? '',
            'created_at_diff' => $game->created_at?->diffForHumans(),
        ];
    }

    /**
     * Shape one seat for the roster, with what this gamemaster may do to it.
     *
     * The three flags are the screen's copy of rules the controllers enforce independently:
     *
     * - `is_self` marks the viewer's own row, so the roster can say which one it is rather than just
     *   omitting a button;
     * - `can_retire` is false for their own seat, which is the "no retiring yourself" rule, and false
     *   for an already-retired one, which is just the state;
     * - `can_change_role` is false for a seat that already holds `Gamemaster`, because taking the role
     *   back is the administrator's, and false for a **retired** seat, because that role is a fact
     *   about the game's history rather than a live decision. It is one flag rather than two because
     *   it answers one question — whether to render the picker — and two flags to be read together
     *   are two things to keep in step.
     *
     * @return array{
     *     id: int,
     *     user_id: int,
     *     user_name: string,
     *     user_email: string,
     *     role: string,
     *     role_label: string,
     *     is_active: bool,
     *     is_self: bool,
     *     can_retire: bool,
     *     can_change_role: bool,
     *     created_at_diff: string|null,
     * }
     */
    private function presentSeat(GameSeat $seat, User $viewer): array
    {
        $isSelf = $seat->user_id === $viewer->getKey();

        return [
            'id' => $seat->id,
            'user_id' => $seat->user_id,
            'user_name' => $seat->user->name,
            'user_email' => $seat->user->email,
            'role' => $seat->role->value,
            'role_label' => $seat->role->label(),
            'is_active' => $seat->is_active,
            'is_self' => $isSelf,
            'can_retire' => $seat->is_active && ! $isSelf,
            'can_change_role' => $seat->is_active && $seat->role !== GameRole::Gamemaster,
            'created_at_diff' => $seat->created_at?->diffForHumans(),
        ];
    }
}
