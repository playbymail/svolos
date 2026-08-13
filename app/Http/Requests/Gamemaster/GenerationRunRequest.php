<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Enums\GenerationStage;
use App\Generation\ClusterGenerator;
use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a generator is handed: the seed, and the two per-stage inputs that ride beside it — whether the
 * cluster is drawn under the traveler constraint, and how far apart the home stellia must stand.
 *
 * ## Regenerating requires a *different* seed, and that is a validation rule
 *
 * The same seed produces byte-identical output — that is the whole point of storing seeds — so
 * regenerating with the seed already on the pending run would redraw the same cluster and read as a
 * button that does nothing. The rule only exists while there is a pending run to differ from: the
 * first run of a stage may use any seed, including one an earlier stage used.
 *
 * **The home stellia stage is exempt, and the exemption is not an inconsistency.** `GenerateHomeStellia`
 * seeds its stream with `seed + attempt`, so the premise the rule rests on — that the same seed redraws
 * the same thing — is false there: pressing Generate again *without touching the seed* is the gesture
 * that stage exists for. Keeping the rule would forbid it, and dropping the rule everywhere would let a
 * gamemaster press a button that genuinely does nothing on the other three stages.
 *
 * Whether the stage may be run at all is **not** decided here. That is a 403 in
 * `Gamemaster\GenerationController` — the game is not in setup, or the stage before it has not been
 * accepted — because there is no field to attach it to and no seed that would make it allowed. This
 * class only answers "is this a seed I can use", which is the question with a field behind it.
 *
 * The one thing here that is neither: an arrangement of homes that **cannot exist** at the separation
 * asked for. The generator throws, and the controller turns that into a message on
 * `minimum_separation` — a posted value that has to change, which is exactly what a message is for.
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
     * means. Neither it nor `minimum_separation` is validated against the stage: each is read by exactly
     * one of them, and a run of another stage recording what it was asked for is both harmless and
     * true. There is no seed-like refusal to make about either, because no value of them is ever wrong
     * — only ever irrelevant.
     *
     * The separation's ceiling is the cluster's **diameter**, which happens to be 30 read either way —
     * the sphere's radius is 15, and so is the hex disc it casts. Past that, no two systems in the game
     * are far enough apart for any arrangement, so the number is asking for something the shape of the
     * world cannot supply rather than something this seed happened not to find. One bound therefore
     * serves both units, and `separation_in_hexes` needs no rule of its own beyond being a boolean.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['bail', ...$this->seedValueRules()];

        $pending = $this->pendingSeed();

        if ($pending !== null && ! $this->redrawsFromTheAttempt()) {
            $rules[] = Rule::notIn([$pending]);
        }

        return [
            'seed' => $rules,
            'traveler' => ['sometimes', 'boolean'],
            'minimum_separation' => ['sometimes', 'integer', 'between:1,'.(ClusterGenerator::RADIUS * 2)],
            'separation_in_hexes' => ['sometimes', 'boolean'],
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
            'minimum_separation.between' => __('The minimum separation must be between 1 and :max hexes, which is as far apart as this cluster reaches.', [
                'max' => ClusterGenerator::RADIUS * 2,
            ]),
        ];
    }

    /**
     * Determine whether this stage produces something new from a seed it has already used.
     *
     * True only for the home stellia, whose stream is seeded with `seed + attempt` — see
     * `App\Actions\Generation\GenerateHomeStellia`. Written as a question about the *stage's* behaviour
     * rather than as `$stage === GenerationStage::HomeStellia` inline, so the reason the rule is skipped
     * is readable at the point it is skipped.
     */
    private function redrawsFromTheAttempt(): bool
    {
        return $this->route('stage') === GenerationStage::HomeStellia;
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
