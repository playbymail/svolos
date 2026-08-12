<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing the game role a seat holds, as done by a gamemaster.
 *
 * The rules are the same set an administrator posts against, on purpose: **a gamemaster being unable
 * to demote another gamemaster is an authorisation rule, not a validation one.** `player` is a
 * perfectly valid role for this field — it is simply not one this requester may move that seat to —
 * so the refusal is a 403 from `Gamemaster\GameSeatController::updateRole()` rather than a 422 saying
 * the value is malformed. Encoding it here instead would make the refusal depend on which seat's row
 * the form request could see, and would report a boundary as a typo.
 */
class GameSeatRoleUpdateRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => $this->gameSeatRoleRules(),
        ];
    }
}
