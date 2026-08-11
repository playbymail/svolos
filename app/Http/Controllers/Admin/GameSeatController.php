<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GameRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GameSeatRoleUpdateRequest;
use App\Http\Requests\Admin\GameSeatStoreRequest;
use App\Models\Game;
use App\Models\GameSeat;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Who sits at one game, and what they are called there.
 *
 * ## There is no destroy action here, and there must not be one
 *
 * A seat leaves a game by being **retired** — `is_active = false` — because engine history keeps
 * referring to the seat: a turn report names the seat that submitted it, so deleting the row turns
 * recorded history into a dangling reference. The screen looks incomplete without a delete button; it is
 * not. `retire()` is the delete button, and `reactivate()` is why the row is worth keeping.
 *
 * The one thing that does destroy seats is deleting the whole game, which cascades — which is why the
 * delete confirmation on the games list names how many seats go with it.
 *
 * ## Every action here is scoped to the game in the URL
 *
 * The routes sit inside `Route::scopeBindings()`, so `{seat}` is resolved through `Game::seats()` rather
 * than globally: a seat belonging to another game **404s** instead of being edited through the wrong
 * game's URL. `Game $game` has to stay in each signature and stay first for that to work — the scoped
 * binding resolves the child against the parameter before it.
 *
 * ## A game role is not an application role
 *
 * `App\Enums\GameRole` carries zero application permissions, and these are `/admin` routes gated by
 * `App\Enums\UserRole::Admin`. Handing somebody a gamemaster seat does not let them reach any of this.
 * See `.ai/rules/roles.md`.
 */
class GameSeatController extends Controller
{
    /**
     * Seat an account at this game with a game role.
     *
     * The duplicate check lives in `GameSeatStoreRequest` and counts **retired** seats, so an account that
     * left the game is refused here with "That account already has a seat in this game." rather than
     * hitting the unique index on `(game_id, user_id)`. Reactivating their existing seat is the way back
     * in.
     */
    public function store(GameSeatStoreRequest $request, Game $game): RedirectResponse
    {
        $seat = $game->seats()->create($request->validated());
        $seat->load('user');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name joined :game as a :role.', [
                'name' => $seat->user->name,
                'game' => $game->name,
                'role' => mb_strtolower($seat->role->label()),
            ]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }

    /**
     * Change the game role a seat holds.
     *
     * This writes `game_seats.role` and nothing else. A retired seat's role can be changed too, and that
     * is not an oversight: the role is what the seat *is* in the game's history, and correcting it should
     * not require putting somebody back into a game they have left.
     */
    public function updateRole(GameSeatRoleUpdateRequest $request, Game $game, GameSeat $seat): RedirectResponse
    {
        /*
         * `enum()` is nullable and the rules make it `required`, so the fallback is unreachable — it is
         * `Player` rather than anything else so that if the rules are ever loosened, the unreachable
         * branch grants the lesser role instead of guessing.
         */
        $seat->role = $request->enum('role', GameRole::class) ?? GameRole::Player;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now a :role in :game.', [
                'name' => $seat->user->name,
                'role' => mb_strtolower($seat->role->label()),
                'game' => $game->name,
            ]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }

    /**
     * Take a seat out of the game without destroying it.
     *
     * **This is the closest thing to a delete that a seat has, and it is deliberately not one.** The row
     * stays, so the engine's history keeps resolving, so the account keeps its place in the unique index
     * on `(game_id, user_id)`, and so the seat can be reactivated instead of recreated.
     */
    public function retire(Game $game, GameSeat $seat): RedirectResponse
    {
        $seat->is_active = false;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name\'s seat in :game was retired.', [
                'name' => $seat->user->name,
                'game' => $game->name,
            ]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }

    /**
     * Put a retired seat back into the game.
     *
     * This is the only way a departed account returns to a game — never a second seat. It keeps whatever
     * game role the seat already carried, because reactivating somebody is not a decision about what they
     * should be called; `updateRole()` is there if it needs to change.
     */
    public function reactivate(Game $game, GameSeat $seat): RedirectResponse
    {
        $seat->is_active = true;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name\'s seat in :game was reactivated.', [
                'name' => $seat->user->name,
                'game' => $game->name,
            ]),
        ]);

        return to_route('admin.games.show', ['game' => $game]);
    }
}
