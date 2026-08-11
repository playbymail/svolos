<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /*
         * A null user cannot reach here — the route is behind `auth` — and if one ever did,
         * `profileRules(null)` is the stricter rule set: the uniqueness check stops ignoring
         * the current row instead of letting an unidentified request through.
         */
        return $this->profileRules($this->user()?->id);
    }
}
