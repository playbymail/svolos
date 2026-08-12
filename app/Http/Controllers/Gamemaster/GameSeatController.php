<?php

namespace App\Http\Controllers\Gamemaster;

use App\Enums\GameRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamemaster\GameSeatRoleUpdateRequest;
use App\Http\Requests\Gamemaster\GameSeatStoreRequest;
use App\Models\Game;
use App\Models\GameSeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roster of one game, managed by somebody who runs it.
 *
 * The same four actions as `Admin\GameSeatController`, under `App\Http\Middleware\EnsureUserIsGamemaster`
 * instead of the admin gate, with **three refusals an administrator does not have**. Everything the
 * administrator's controller says still applies here and is not repeated: there is no destroy action and
 * there must not be one (a seat is retired, never deleted, because engine history keeps referring to it),
 * and every action is scoped to the game in the URL by `Route::scopeBindings()`, which is why `Game $game`
 * stays ahead of `GameSeat $seat` in each signature.
 *
 * ## The three refusals
 *
 * - **You cannot retire yourself.** Leaving a game you run is not a thing you do to your own roster; it
 *   removes the last person able to reach this screen if you are the only gamemaster, and it is
 *   indistinguishable from an accident. An administrator retires you.
 * - **You cannot take the gamemaster role off a seat.** Handing it *out* is allowed — promoting a player
 *   is how a game gets a second pair of hands — but a gamemaster demoting a peer is one gamemaster
 *   ejecting another from the only screen that can undo it. Only an administrator may do that, through
 *   `Admin\GameSeatController::updateRole()`.
 * - **You cannot change the role on a retired seat.** The administrator's copy of the action allows it
 *   on purpose; from inside the game it is the wrong power, because the seat is already out of the
 *   roster and rewriting its role only changes what the record says somebody was. Reactivate the seat
 *   and change it, or ask an administrator.
 *
 * All three are 403s rather than validation errors: the value posted is well formed, it is the requester
 * who may not post it. The screen hides the corresponding controls (`can_retire` and `can_change_role` in
 * `Gamemaster\GameController::presentSeat()`), but these checks are the boundary — the flags are not.
 *
 * ## A game role is not an application role
 *
 * Nothing here reads `users.role`, `isAdmin()` or `App\Enums\UserRole`, and it never may. Being an
 * administrator does not hand out a seat, and holding a gamemaster seat grants nothing outside this one
 * game — see `App\Enums\GameRole` and `.ai/rules/roles.md`.
 */
class GameSeatController extends Controller
{
    /**
     * Seat an account at this game with a game role.
     *
     * Either role may be handed out, gamemaster included. The duplicate check lives in
     * `Gamemaster\GameSeatStoreRequest` and counts **retired** seats, so an account that left the game is
     * refused with "That account already has a seat in this game." rather than hitting the unique index on
     * `(game_id, user_id)`. Reactivating their existing seat is the way back in.
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

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Change the game role a seat holds, on the seats and in the direction a gamemaster may change it.
     *
     * ## A retired seat's role is out of reach here
     *
     * The administrator's copy of this action deliberately allows it — the role is what the seat *was*
     * in the game's history, and correcting a mistake should not require putting somebody back into a
     * game they have left. That is an argument for *correcting history*, which is the administrator's
     * to do. From inside the game it is the wrong power to hold: the seat is already out of the roster,
     * so no live decision turns on its role, and rewriting it changes only what the record says
     * somebody was. A gamemaster who wants the role changed reactivates the seat and changes it, or
     * asks an administrator.
     *
     * ## And a gamemaster's seat cannot lose the role
     *
     * The refusal is written as "the seat is a gamemaster's and the new role is not a gamemaster's"
     * rather than "the new role is player", so a third game role added later cannot be used as a way
     * around it: anything that is not still `Gamemaster` is a demotion. Setting a gamemaster seat to
     * gamemaster is a no-op and is allowed through, because refusing a change that changes nothing would
     * report a boundary to somebody who has not crossed it.
     *
     * This covers demoting **yourself** as well, which is the same rule seen from the other side.
     */
    public function updateRole(GameSeatRoleUpdateRequest $request, Game $game, GameSeat $seat): RedirectResponse
    {
        abort_unless($seat->is_active, Response::HTTP_FORBIDDEN);

        /*
         * `enum()` is nullable and the rules make it `required`, so the fallback is unreachable — it is
         * `Player` rather than anything else so that if the rules are ever loosened, the unreachable
         * branch grants the lesser role instead of guessing. Here that fallback is also the *refused*
         * value on a gamemaster's seat, so a loosened rule fails closed rather than demoting silently.
         */
        $role = $request->enum('role', GameRole::class) ?? GameRole::Player;

        abort_if(
            $seat->role === GameRole::Gamemaster && $role !== GameRole::Gamemaster,
            Response::HTTP_FORBIDDEN,
        );

        $seat->role = $role;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now a :role in :game.', [
                'name' => $seat->user->name,
                'role' => mb_strtolower($seat->role->label()),
                'game' => $game->name,
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Take somebody else's seat out of the game without destroying it.
     *
     * **Your own seat is refused.** The comparison is against the seat's `user_id` rather than against
     * anything about roles, so it holds however the roster is arranged: the one seat a gamemaster may not
     * retire is the one they are sitting in.
     *
     * As on the administrator's screen the row stays, so the engine's history keeps resolving, so the
     * account keeps its place in the unique index on `(game_id, user_id)`, and so the seat can be
     * reactivated instead of recreated.
     */
    public function retire(Request $request, Game $game, GameSeat $seat): RedirectResponse
    {
        abort_if($seat->user_id === $this->authenticatedUser($request)->getKey(), Response::HTTP_FORBIDDEN);

        $seat->is_active = false;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name\'s seat in :game was retired.', [
                'name' => $seat->user->name,
                'game' => $game->name,
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Put a retired seat back into the game.
     *
     * It keeps whatever game role it already carried, gamemaster included: reactivating somebody is not a
     * decision about what they should be called, and restoring the role a seat already held is not the
     * same act as handing the role out. `updateRole()` is there if it needs to change — subject to the
     * demotion rule, which is why a retired gamemaster's seat comes back as a gamemaster's.
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

        return to_route('gamemaster.games.show', ['game' => $game]);
    }
}
