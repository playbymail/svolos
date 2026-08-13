<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AgentStoreRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The name reuses `nameRules()` so an agent is held to the same limits as a person — it appears in
     * the same tables and, before long, in the same turn reports.
     *
     * The address is **optional**, which is the one departure from the profile rule set. Left out, it
     * is derived from the name by `App\Actions\Agents\CreateAgent` on the reserved `.invalid` domain;
     * supplied, it still has to pass `emailRules()`, so it is a real address shape and unique across
     * `users`. Uniqueness matters more here than it looks: an agent occupying an address would
     * otherwise block a person being invited at it.
     *
     * `is_agent` is not a field and must never become one. It is assigned by the action, for the same
     * reason `role` is assigned rather than filled — see `.ai/rules/roles.md`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(required: false),
        ];
    }

    /**
     * Treat a blank address as an absent one.
     *
     * The form posts an empty string for a field the administrator left alone, and `nullable` does
     * not catch that — `''` would reach `email` and fail as a malformed address rather than being
     * read as "derive one for me".
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('email') === '') {
            $this->merge(['email' => null]);
        }
    }
}
