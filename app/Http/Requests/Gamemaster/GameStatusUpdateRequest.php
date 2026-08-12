<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The gamemaster's half of `Admin\GameUpdateRequest`: the status, and nothing else.
 *
 * **The absence of `name` and `short_name` is the rule this class exists for.** A gamemaster runs a
 * game; naming it is the administrator's decision, and a short name in particular leaves the
 * application — it goes into turn reports and generated file names — so renaming one silently
 * relabels artefacts that already exist elsewhere.
 *
 * Leaving the two fields out of the rules is what enforces that, not a check somewhere else:
 * `Gamemaster\GameController::update()` fills the model from `validated()`, and `validated()` returns
 * only keys that were validated. A posted `name` is therefore ignored rather than rejected — the form
 * on the screen does not offer the field, so a request carrying one is not a gamemaster making a
 * mistake to be told about. Adding either field here re-opens the hole in one line, which is why the
 * point is written down here and asserted in `tests/Feature/Gamemaster/GameManagementTest.php`.
 */
class GameStatusUpdateRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The status rules are shared with the administrator's request, so both areas refuse an `Active`
     * game whose generation is unfinished with the same message. Everything else is open: nothing
     * forces a game forward, so a completed game can be reopened and an archived one restored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $game = $this->route('game');

        return [
            'status' => $this->gameStatusRules($game instanceof Game ? $game : null),
        ];
    }
}
