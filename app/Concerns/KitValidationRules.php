<?php

namespace App\Concerns;

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Models\KitTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The rules two kit-template requests share.
 *
 * `KitTemplateStoreRequest` and `KitTemplateUpdateRequest` both validate a name against the same
 * per-owner uniqueness, and the update request and the editor both validate the same holdings
 * arrays. One copy, for the reason `GameValidationRules` is one copy: two sets of rules describing
 * the same contract drift, and the half that drifts is the half nobody is looking at.
 *
 * ## These rules are shape, not meaning
 *
 * They check that a posted payload is the right *shape* — arrays where arrays belong, a known kind,
 * a whole quantity. Everything about whether it is a **usable kit** is `App\Generation\Kit`'s:
 * whether the inventory is one that kind may sit in, whether the technology level suits it, whether
 * two holdings collide in the `units` unique key, whether every kind a game opens with is described.
 *
 * That split is deliberate and worth keeping. Those refusals have to exist in `Kit` regardless,
 * because an uploaded document never passes through these rules at all — restating any of them here
 * would be a second copy that can disagree with the first, and the one that would win is whichever
 * the upload path happens to miss.
 */
trait KitValidationRules
{
    /**
     * Get the validation rules used to validate a kit template's name.
     *
     * Unique **per owner**, not globally: the library is private, so two gamemasters may each keep a
     * kit called "Lean start" without either of them learning that the other exists. The owner is
     * taken from the signed-in account rather than from the payload, so a posted `user_id` cannot
     * move the check to somebody else's shelf.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function kitNameRules(?int $kitTemplateId = null): array
    {
        return [
            'required',
            'string',
            'max:255',
            Rule::unique(KitTemplate::class, 'name')
                ->where('user_id', $this->user()?->getKey())
                ->ignore($kitTemplateId),
        ];
    }

    /**
     * Get the validation rules used to validate the holdings a gamemaster edited in the browser.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function kitHoldingRules(): array
    {
        return [
            'entities' => ['required', 'array', 'min:1'],
            'entities.*.type' => [
                'required',
                Rule::in(array_map(
                    fn (EntityType $type): string => $type->value,
                    EntityType::startingKinds(),
                )),
            ],
            'entities.*.holdings' => ['required', 'array', 'min:1'],
            'entities.*.holdings.*.type' => ['required', Rule::enum(UnitType::class)],
            'entities.*.holdings.*.inventory' => ['required', Rule::enum(Inventory::class)],
            'entities.*.holdings.*.quantity' => ['required', 'integer', 'min:1'],
            /*
             * `0` is a real value here and means "this kind has no technology level" — see
             * `.ai/rules/units.md` on why the absent case is `0` rather than `NULL`. Which kinds may
             * carry which levels is `UnitType::assertTechnologyLevel()`'s, enforced through `Kit`.
             */
            'entities.*.holdings.*.technology_level' => [
                'required',
                'integer',
                'between:'.UnitType::NO_TECHNOLOGY_LEVEL.','.UnitType::MAXIMUM_TECHNOLOGY_LEVEL,
            ],
        ];
    }

    /**
     * Get the messages for the rules above.
     *
     * @return array<string, string>
     */
    protected function kitMessages(): array
    {
        return [
            'name.unique' => __('You already have a kit with that name.'),
            'entities.required' => __('A kit needs to describe what each kind of entity begins with.'),
            'entities.*.holdings.required' => __('Each entity needs at least one holding.'),
            'entities.*.holdings.*.quantity.min' => __('A holding needs a quantity of at least one. Remove it instead.'),
        ];
    }
}
