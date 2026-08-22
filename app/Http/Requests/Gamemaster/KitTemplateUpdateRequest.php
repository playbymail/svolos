<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\KitValidationRules;
use App\Models\KitTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A kit as a gamemaster edited it in the browser.
 *
 * The rules here check the **shape** of what was posted and nothing about whether it is a usable
 * kit; `App\Generation\Kit::fromDocument()` makes every other refusal, which is where an uploaded
 * document is judged too. See `App\Concerns\KitValidationRules` for why that split is deliberate and
 * why restating any of those rules here would be the wrong kind of thorough.
 *
 * The name's uniqueness ignores this row, so saving a kit without renaming it is not a collision
 * with itself.
 */
class KitTemplateUpdateRequest extends FormRequest
{
    use KitValidationRules;

    /**
     * Turn the numbers a browser posted back into numbers.
     *
     * **An HTML form posts strings, and `App\Generation\Kit` requires real integers.** That is not
     * a inconsistency to soften on either side: an uploaded document is JSON, where `"quantity": "31"`
     * genuinely *is* malformed and should be refused — so the parser stays strict — while a form field
     * has no way to send anything but text, so the string is correct and the conversion belongs here.
     *
     * The `integer` rule does not do it: it *accepts* a numeric string and `validated()` hands the
     * string straight back. Without this the parser refuses every edit with "needs a whole quantity",
     * which is a true sentence about a payload nobody could have posted any other way.
     *
     * This is the same seam `Admin\GameStoreRequest` uppercases a short name in, and for the same
     * reason: normalise before the rules run, so everything downstream sees one shape.
     */
    protected function prepareForValidation(): void
    {
        $entities = $this->input('entities');

        if (! is_array($entities)) {
            return;
        }

        $this->merge([
            'entities' => array_map(
                fn (mixed $entity): mixed => is_array($entity) && is_array($entity['holdings'] ?? null)
                    ? [
                        ...$entity,
                        'holdings' => array_map(
                            fn (mixed $holding): mixed => is_array($holding)
                                ? [
                                    ...$holding,
                                    'quantity' => self::asInteger($holding['quantity'] ?? null),
                                    'technology_level' => self::asInteger($holding['technology_level'] ?? null),
                                ]
                                : $holding,
                            $entity['holdings'],
                        ),
                    ]
                    : $entity,
                $entities,
            ),
        ]);
    }

    /**
     * Read one posted number, leaving anything that is not one alone.
     *
     * A value that is not numeric is passed through untouched so the rules refuse it with their own
     * message, rather than being silently turned into `0` — which would post a technology level of
     * nought onto a kind that needs one and fail much further along.
     */
    private static function asInteger(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $kitTemplate = $this->route('kitTemplate');

        return [
            'name' => $this->kitNameRules($kitTemplate instanceof KitTemplate ? $kitTemplate->getKey() : null),
            ...$this->kitHoldingRules(),
        ];
    }

    /**
     * Get the messages for the rules above.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->kitMessages();
    }
}
