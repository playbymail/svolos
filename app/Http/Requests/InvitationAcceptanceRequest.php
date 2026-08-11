<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InvitationAcceptanceRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * **`email` is absent on purpose.** The address is taken from the invitation, so validating a
     * posted one would imply the controller reads it — it does not. The form renders it read-only for
     * the person filling the form in, and editing that field in the browser changes nothing.
     *
     * The rules themselves come from the shared traits, the same ones
     * `App\Actions\Fortify\CreateNewUser` validates with when it creates the account. This request is
     * where the failures are reported from; the action is the last line of defence.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
