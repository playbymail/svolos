<?php

namespace App\Http\Requests\Admin;

use App\Concerns\GameValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AgentSeatStoreRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The mirror image of `GameSeatStoreRequest`: that one knows the game and picks an account, this
     * one knows the agent and picks a game. Both go through `App\Concerns\GameValidationRules`, so the
     * uniqueness that counts **retired** seats is stated once and cannot drift between the two — which
     * is the whole reason those rules live in a trait.
     *
     * There is no `role` field, and that is deliberate rather than an omission. Seating an agent from
     * its own screen is the ordinary case, and the ordinary case is a player; handing out a
     * *gamemaster* seat is a decision about how a game is run, so it stays on the game's roster where
     * `GameSeatRoleForm` already lives. `App\Http\Controllers\Admin\AgentSeatController` assigns
     * `GameRole::Player` explicitly rather than leaning on the column default, so a change to that
     * default cannot quietly promote anybody.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $agent = $this->route('user');

        return [
            'game_id' => $this->gameSeatGameRules($agent instanceof User ? $agent->id : null),
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
