<?php

namespace App\Http\Requests\Admin;

use App\Concerns\GameValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GameStoreRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * `status` is deliberately absent: a new game always starts in `setup`, which is the column default,
     * so there is nothing to choose and nothing a post could override.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->gameNameRules(),
            'short_name' => $this->gameShortNameRules(),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gameMessages();
    }

    /**
     * Prepare the data for validation.
     *
     * The short name is uppercased **here**, before the rules run, so that the `[A-Z0-9-]` pattern is
     * checked against the folded value: `run-1` becomes `RUN-1` and is accepted, while `run 1` becomes
     * `RUN 1` and is rejected for the space. See `GameValidationRules::gameShortNameRules()`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('short_name')) {
            $this->merge([
                'short_name' => $this->normalizedShortName($this->string('short_name')->toString()),
            ]);
        }
    }
}
