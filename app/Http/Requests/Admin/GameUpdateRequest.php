<?php

namespace App\Http\Requests\Admin;

use App\Concerns\GameValidationRules;
use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameUpdateRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Both uniqueness rules ignore the game being edited, so saving the form without touching either
     * name is not a conflict with itself. The id comes from the route binding; if it were ever missing,
     * the rules fall back to the stricter form that ignores nothing (see
     * `GameValidationRules::uniqueGameRule()`).
     *
     * Any status may be chosen. The enum's order is the order a game normally travels through, but
     * nothing forces a game forward — a completed game can be reopened, and an archived one restored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $game = $this->route('game');
        $gameId = $game instanceof Game ? $game->id : null;

        return [
            'name' => $this->gameNameRules($gameId),
            'short_name' => $this->gameShortNameRules($gameId),
            'status' => ['required', Rule::enum(GameStatus::class)],
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
     * The short name is uppercased **here**, before the rules run — the same ordering as
     * `GameStoreRequest`, so renaming a game folds its short name exactly as creating one does.
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
