<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GameRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgentSeatStoreRequest;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Seating an agent at a game, from the agent's own screen.
 *
 * This is the same act as `GameSeatController::store()` approached from the other side, and it exists
 * for a workflow reason rather than a modelling one. A token belongs to a seat, so a newly created
 * agent has nowhere to hang one until it is seated — and sending an administrator off to a game's
 * roster and back again to finish creating an agent made the token look impossible to issue.
 *
 * What it deliberately does **not** do is become a second way to run a roster:
 *
 * - it refuses any account that is not an agent, so a person is still seated from the game screen
 *   where the whole roster is visible and a gamemaster can do it;
 * - it seats as a **player** and offers no role choice. Who runs a game is a decision about the game,
 *   and it stays on the game's roster with `GameSeatRoleForm`;
 * - the uniqueness that counts retired seats comes from `App\Concerns\GameValidationRules`, the same
 *   trait the roster requests use, so the two cannot drift.
 */
class AgentSeatController extends Controller
{
    /**
     * Seat an agent at a game as a player.
     *
     * `game_id` and `user_id` are assigned explicitly rather than mass-assigned: neither is in
     * `GameSeat`'s `#[Fillable]` list, and `game_id` in particular arrives from request input here
     * rather than from a route binding, which is exactly the case spelling the write out protects.
     */
    public function store(AgentSeatStoreRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->isAgent(), 404);

        $game = Game::query()->findOrFail($request->integer('game_id'));

        $seat = new GameSeat;
        $seat->game_id = $game->id;
        $seat->user_id = $user->id;
        $seat->role = GameRole::Player;
        $seat->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name joined :game as a player. Issue a token to let it act there.', [
                'name' => $user->name,
                'game' => $game->name,
            ]),
        ]);

        return to_route('admin.agents.show', $user);
    }
}
