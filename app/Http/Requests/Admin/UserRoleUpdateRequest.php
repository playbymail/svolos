<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRoleUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only the role is accepted. This is the one screen allowed to change `users.role`, and it does
     * so by assigning the column explicitly — `role` is deliberately absent from `User`'s
     * `#[Fillable]` list (see `.ai/rules/roles.md`), so validating it here does not make it
     * mass-assignable anywhere else.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
