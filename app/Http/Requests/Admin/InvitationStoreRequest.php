<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitationStoreRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * `emailRules()` is reused rather than restated, which brings the uniqueness check against
     * `users` with it: an address that already has an account cannot be invited, because the
     * acceptance flow would only fail on that same rule after the mail had gone out.
     *
     * There is deliberately **no** uniqueness rule against `invitations` — the action upserts on the
     * address, so re-inviting somebody is a supported way to reissue their link.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('That email address already has an account.'),
        ];
    }
}
