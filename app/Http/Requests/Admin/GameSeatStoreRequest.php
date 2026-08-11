<?php

namespace App\Http\Requests\Admin;

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameSeatStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * ## The uniqueness rule counts retired seats, and that is the point of it
     *
     * `Rule::unique(GameSeat::class, 'user_id')->where('game_id', …)` deliberately carries **no**
     * `is_active` condition. Seats are retired, never deleted (see `App\Models\GameSeat`), so an account
     * that has left the game still owns a row — and the way back in is to *reactivate* that row, not to
     * create a second one. Adding `->where('is_active', true)` here would let a second row be attempted,
     * which the unique index on `(game_id, user_id)` would then refuse with a database error instead of a
     * validation message.
     *
     * The screen agrees with this rule rather than restating it: the assignable-accounts list on
     * `admin/games/Show` excludes every account that already holds a seat, active or retired, so a
     * retired holder is not offered here in the first place. This rule is what makes a hand-made post
     * behave the same way.
     *
     * The scoping `game_id` comes from the route binding. If it were ever missing, the fallback rule is
     * the stricter one — unique across every game — so an unidentified request is refused rather than
     * allowed to create an unscoped seat.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $game = $this->route('game');

        $unique = Rule::unique(GameSeat::class, 'user_id');

        if ($game instanceof Game) {
            $unique->where('game_id', $game->id);
        }

        return [
            'user_id' => ['required', 'integer', Rule::exists(User::class, 'id'), $unique],
            'role' => ['required', Rule::enum(GameRole::class)],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * The duplicate message names the situation rather than the constraint: an administrator who picked
     * an account that has quietly been retired from this game needs to be told to reactivate the seat,
     * not that a unique index rejected them.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.unique' => __('That account already has a seat in this game.'),
            'user_id.exists' => __('That account no longer exists.'),
        ];
    }
}
