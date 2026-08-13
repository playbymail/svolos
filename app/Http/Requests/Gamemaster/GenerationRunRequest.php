<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Enums\GenerationStage;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a generator is handed: the seed, and whether the cluster is drawn under the traveler
 * constraint — whether this is the first run of a stage or a replacement for one.
 *
 * ## Regenerating requires a *different* seed, and that is a validation rule
 *
 * The same seed produces byte-identical output — that is the whole point of storing seeds — so
 * regenerating with the seed already on the pending run would redraw the same cluster and read as a
 * button that does nothing. The rule only exists while there is a pending run to differ from: the
 * first run of a stage may use any seed, including one an earlier stage used.
 *
 * Whether the stage may be run at all is **not** decided here. That is a 403 in
 * `Gamemaster\GenerationController` — the game is not in setup, or the stage before it has not been
 * accepted — because there is no field to attach it to and no seed that would make it allowed. This
 * class only answers "is this a seed I can use", which is the question with a field behind it.
 */
class GenerationRunRequest extends FormRequest
{
    use GameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * `bail` stops at the first failure, so a seed that is both out of range and unchanged produces one
     * message rather than two for the same number.
     *
     * `traveler` is optional because an unchecked checkbox posts nothing at all, which is the state it
     * means. It is not validated against the stage: only the cluster stage reads it, and a run of
     * another stage recording that it was asked for is both harmless and true. There is no seed-like
     * refusal to make here, because no value of it is ever wrong.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['bail', ...$this->seedValueRules()];

        $pending = $this->pendingSeed();

        if ($pending !== null) {
            $rules[] = Rule::notIn([$pending]);
        }

        return [
            'seed' => $rules,
            'traveler' => ['sometimes', 'boolean'],
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
            ...$this->gameSeedMessages(),
            'seed.not_in' => __('Choose a seed other than the one that produced this.'),
        ];
    }

    /**
     * Get the seed of the run this request would replace, if there is one.
     *
     * Reads the *stored* run rather than anything the request said about it. A stage with no pending
     * run — a first run, or a stage that is locked and about to be refused — has nothing to differ
     * from, so the rule is simply absent.
     */
    private function pendingSeed(): ?int
    {
        $game = $this->route('game');
        $stage = $this->route('stage');

        if (! $game instanceof Game || ! $stage instanceof GenerationStage) {
            return null;
        }

        $run = $game->generationRunFor($stage);

        return $run?->isPending() === true ? $run->seed : null;
    }
}
