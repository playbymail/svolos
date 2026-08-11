<?php

namespace App\Http\Requests\Admin;

use App\Enums\GameRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameSeatRoleUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only the game role is accepted. `is_active` is not validated here and never will be — a seat is
     * retired and reactivated through its own two endpoints, so a change of role can never move a seat
     * in or out of the game as a side effect. `GameSeat`'s `#[Fillable]` list leaves `is_active` out for
     * the same reason.
     *
     * `App\Enums\GameRole` carries no application permissions, so unlike
     * `Admin\UserRoleUpdateRequest` — which guards the boundary between a member and an administrator —
     * there is nothing being escalated here. The two role systems stay unrelated; see
     * `.ai/rules/roles.md`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(GameRole::class)],
        ];
    }
}
