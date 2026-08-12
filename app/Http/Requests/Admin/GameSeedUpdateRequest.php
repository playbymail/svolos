<?php

namespace App\Http\Requests\Admin;

use App\Concerns\GameValidationRules;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The seed, on its own, from the administrator's screen.
 *
 * It is a separate request — and a separate endpoint — from `GameUpdateRequest` rather than one more
 * field on it, because the seed answers to the game's status while the name, short name and status do
 * not. Folded into the metadata form, saving a *renamed* game that had already left setup would carry
 * the seed input along and be rejected for a field nobody touched.
 *
 * `Gamemaster\GameSeedUpdateRequest` is the same class in another namespace, on purpose: an
 * administrator and a gamemaster may both change a seed, both only during setup, and the rule they
 * share lives in `App\Concerns\GameValidationRules` so the two cannot drift apart.
 */
class GameSeedUpdateRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The game comes from the route binding, so the status the rule reads is the stored one — not
     * anything the request could claim about it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $game = $this->route('game');

        return [
            'seed' => $this->gameSeedRules($game instanceof Game ? $game : null),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gameSeedMessages();
    }
}
