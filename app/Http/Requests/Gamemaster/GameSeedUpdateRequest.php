<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The seed, on its own, from the gamemaster's screen.
 *
 * This differs from `Admin\GameSeedUpdateRequest` by namespace alone, exactly as the two
 * `GameSeatStoreRequest` classes do. **That is the decision, not an oversight.** Changing a seed is one
 * of the things a gamemaster may do — a game in setup has not been played yet, so there is no run to
 * rewrite — and the condition on it is a fact about the game rather than about who is asking, so both
 * areas answer to the one rule in `App\Concerns\GameValidationRules::gameSeedRules()`.
 *
 * Note what this class does *not* do: it validates the seed and nothing else, so it cannot become a
 * second way to post a name or a status, the way `GameStatusUpdateRequest` cannot become a way to post
 * a name.
 */
class GameSeedUpdateRequest extends FormRequest
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
