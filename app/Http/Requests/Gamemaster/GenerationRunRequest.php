<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Enums\GenerationStage;
use App\Generation\ClusterGenerator;
use App\Models\Game;
use App\Models\GenerationRun;
use App\Models\KitTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a generator is handed: the seed, and the per-stage inputs that ride beside it — whether the
 * cluster is drawn under the traveler constraint, how far apart the home stellia must stand, and the
 * document a home template may arrive as.
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
 * **The units stage is exempt for the opposite reason**, and the two are worth telling apart. There
 * the same seed does not redraw the same thing because the seed is not in the stream; here the seed is
 * not in the stream at all, so *every* seed produces the same kit. Regenerating it is about a roster
 * that has changed, and asking for a different number first would be asking about the wrong thing.
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

        if ($pending !== null
            && ! $this->redrawsFromTheAttempt()
            && ! $this->templateDecidedByADocument()
            && ! $this->redrawsFromTheRoster()
        ) {
            $rules[] = Rule::notIn([$pending]);
        }

        return [
            'seed' => $rules,
            'traveler' => ['sometimes', 'boolean'],
            'minimum_separation' => ['sometimes', 'integer', 'between:1,'.(ClusterGenerator::RADIUS * 2)],
            'separation_in_hexes' => ['sometimes', 'boolean'],
            ...$this->templateRules(),
            ...$this->kitRules(),
        ];
    }

    /**
     * Get the rules for the document a home stellia template may arrive as.
     *
     * **The one pair of rules that is tied to a stage, and the exception proves the sentence above.**
     * `traveler` and `minimum_separation` are never wrong on another stage, only ever irrelevant, so
     * they are validated unconditionally. A template is different in both directions: on its own stage
     * a missing one is genuinely a failure — there are two ways to settle a template and the
     * gamemaster has to pick one — and on any other stage a posted file is not a value that stage
     * ignores, it is a request nothing would ever read.
     *
     * `required_without` rather than `required`: ticking the box to draw one is the other way to
     * satisfy the stage, and an unticked checkbox posts nothing at all.
     *
     * The document's *contents* are deliberately not checked here. `HomeTemplate::fromJson()` is what
     * knows what a template is, and it reports through `GenerationFailed`'s field — landing its
     * messages on this same `template` key, so a gamemaster cannot tell which half refused them.
     *
     * @return array<string, array<mixed>>
     */
    private function templateRules(): array
    {
        if ($this->route('stage') !== GenerationStage::HomeStelliaTemplate) {
            return [];
        }

        return [
            'generate_template' => ['sometimes', 'boolean'],
            'template' => ['required_without:generate_template', 'file', 'mimes:json,txt', 'max:64'],
        ];
    }

    /**
     * Get the rules for the kit the units stage may arrive with.
     *
     * The **second** pair tied to a stage, for the same reason the template's is: on this stage a kit
     * is genuinely part of the input, and on any other one a posted kit is a value nothing would ever
     * read. Everything `templateRules()` says applies here unchanged.
     *
     * `kit_source` is `sometimes` and its absence means `generate`, which is the same shape as an
     * unticked `generate_template` — a stage that is asked for nothing draws one. It matters
     * practically as well as conceptually: `withAcceptedUnits()` in `tests/Pest.php` walks a whole
     * world into existence by posting a bare seed at every stage, and a `required` rule here would
     * fail that helper, and with it a great many tests about nothing to do with kits.
     *
     * **`kit_template_id` is scoped to the signed-in account inside the `exists` rule**, which is what
     * makes "that kit is not yours" a message rather than a 403. A kit template is a *posted value*
     * and not a route-bound model, so it falls on the message side of the line this area draws — see
     * `Gamemaster\GenerationController`. Scoping the lookup gets the refusal and its sentence in one
     * rule, and cannot leak whether somebody else's kit with that id exists.
     *
     * The document's *contents* are `App\Generation\Kit::fromJson()`'s, reported through
     * `GenerationFailed`'s field so its messages land on this same `kit` key.
     *
     * @return array<string, array<mixed>>
     */
    private function kitRules(): array
    {
        if ($this->route('stage') !== GenerationStage::Assets) {
            return [];
        }

        return [
            'kit_source' => ['sometimes', Rule::in(['generate', 'saved', 'upload'])],
            'kit_template_id' => [
                'required_if:kit_source,saved',
                'integer',
                Rule::exists(KitTemplate::class, 'id')->where('user_id', $this->user()?->getKey()),
            ],
            'kit' => ['required_if:kit_source,upload', 'file', 'mimes:json,txt', 'max:64'],
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
            'template.required_without' => __('Upload a template document, or tick the box to generate one from the seed.'),
            'template.mimes' => __('A template is a JSON document.'),
            'kit_template_id.required_if' => __('Choose one of your saved kits.'),
            'kit_template_id.exists' => __('That is not one of your kits.'),
            'kit.required_if' => __('Upload a kit document, or choose another way to settle the kit.'),
            'kit.mimes' => __('A kit is a JSON document.'),
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
     * Determine whether this stage's outcome is decided by the roster rather than by the seed.
     *
     * True only for the units, and it is the counterpart of `redrawsFromTheAttempt()` rather than of
     * `templateDecidedByADocument()` — both say the same seed can honestly produce something new.
     *
     * **The reason changed when kits became drawable, and the rule did not.** This exemption used to
     * be called `ignoresTheSeedEntirely()` and rested on the units stage drawing nothing at all: every
     * seed gave every player the same kit, so demanding a different number was asking somebody to
     * change something nothing read. `App\Generation\KitGenerator` now draws a kit from the seed, so
     * that premise is gone.
     *
     * What replaces it is the reason a gamemaster actually regenerates this stage: **a roster that has
     * grown since it ran.** A player seated after the homes were arranged has nowhere to stand and is
     * skipped, and the remedy is running the stage again — see `App\Actions\Generation\GenerateUnits`.
     * The seed is not the whole input here; the seats are, and running the same seed against a
     * different roster places genuinely different entities.
     *
     * Keeping the rule instead would break exactly that repair: the only way to seat a latecomer would
     * be to change the seed, which would redraw the kit every other player has already been given. The
     * cost of the exemption is that pressing the button twice with nothing changed quietly repeats
     * itself, which was already true of this stage and is what accepting is for.
     */
    private function redrawsFromTheRoster(): bool
    {
        return $this->route('stage') === GenerationStage::Assets;
    }

    /**
     * Determine whether a document is what decides this template, on either side of the comparison.
     *
     * The rule above rests on one premise — that the same seed redraws the same thing — and a document
     * breaks it in **both** directions, which is why this asks about the pending run as well as about
     * the request:
     *
     * - **uploading over anything**: two documents under one seed are two different templates, so a
     *   gamemaster correcting a typo in the file they just sent must not be told to change a number
     *   that had nothing to do with it;
     * - **drawing over an upload**: a drawn template is not the document that is standing there, so
     *   the seed that produced the pending run did not produce this and there is nothing to repeat.
     *
     * Only the remaining case keeps the rule: drawing over a drawn template, where the seed really is
     * the whole of the input and pressing the button again unchanged would produce the same nine
     * planets.
     */
    private function templateDecidedByADocument(): bool
    {
        if ($this->route('stage') !== GenerationStage::HomeStelliaTemplate) {
            return false;
        }

        return $this->hasFile('template')
            || is_string($this->pendingRun()?->template['file'] ?? null);
    }

    /**
     * Get the seed of the run this request would replace, if there is one.
     */
    private function pendingSeed(): ?int
    {
        return $this->pendingRun()?->seed;
    }

    /**
     * Get the run this request would replace, if there is one.
     *
     * Reads the *stored* run rather than anything the request said about it. A stage with no pending
     * run — a first run, or a stage that is locked and about to be refused — has nothing to differ
     * from, so the rule is simply absent.
     */
    private function pendingRun(): ?GenerationRun
    {
        $game = $this->route('game');
        $stage = $this->route('stage');

        if (! $game instanceof Game || ! $stage instanceof GenerationStage) {
            return null;
        }

        $run = $game->generationRunFor($stage);

        return $run?->isPending() === true ? $run : null;
    }
}
