<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Seating an account, as done from the gamemaster's own roster screen.
 *
 * Identical in contract to `Admin\GameSeatStoreRequest` — same fields, same uniqueness that counts
 * retired seats, same messages — because it is the same operation on the same table, and the rules
 * live in `App\Concerns\GameValidationRules` so the two cannot drift. A gamemaster may seat somebody
 * as a gamemaster: handing out the role is not the restricted operation. **Taking it away is**, and
 * that lives in `Gamemaster\GameSeatController::updateRole()`.
 */
class GameSeatStoreRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $game = $this->route('game');
        $gameId = $game instanceof Game ? $game->id : null;

        return [
            'user_id' => $this->gameSeatUserRules($gameId),
            'role' => $this->gameSeatRoleRules(),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gameSeatMessages();
    }
}
