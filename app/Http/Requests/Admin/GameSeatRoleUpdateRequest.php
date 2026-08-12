<?php

namespace App\Http\Requests\Admin;

use App\Concerns\GameValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GameSeatRoleUpdateRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Only the game role is accepted; see `GameValidationRules::gameSeatRoleRules()` for why
     * `is_active` is not validated here and never will be.
     *
     * An administrator may set either role on any seat. `App\Enums\GameRole` carries no application
     * permissions, so unlike `Admin\UserRoleUpdateRequest` — which guards the boundary between a member
     * and an administrator — there is nothing being escalated here. The two role systems stay unrelated;
     * see `.ai/rules/roles.md`. The *gamemaster's* copy of this screen is the one with a restriction, and
     * it is a 403 in its controller rather than a rule here.
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
