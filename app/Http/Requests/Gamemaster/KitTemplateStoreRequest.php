<?php

namespace App\Http\Requests\Gamemaster;

use App\Concerns\GameValidationRules;
use App\Concerns\KitValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new kit in the library: drawn from a seed, or read from a document.
 *
 * The same two-ways-in shape the home stellia template stage has, and posted the same way — one
 * multipart form carrying both possibilities, with `source` saying which was meant. There is no
 * third path here: a kit written entirely from scratch would start from an empty holdings list, and
 * starting from the catalogue's own kit and editing it is both easier and harder to get wrong.
 *
 * The seed is validated against `App\Concerns\GameValidationRules::seedValueRules()` — the same
 * range a game's seed uses, because it is drawn through the same `Mt19937` engine and stored in the
 * same width of column. `App\Generation\Kit`'s own bounds mirror it, and `KitTest` pins the pair
 * together.
 */
class KitTemplateStoreRequest extends FormRequest
{
    use GameValidationRules;
    use KitValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->kitNameRules(),
            'source' => ['required', Rule::in(['generate', 'upload'])],
            /*
             * `exclude_unless` rather than `required_if`, and the difference is the whole rule.
             * `seedValueRules()` opens with `required`, so conditionally *adding* a rule in front of
             * it leaves the seed mandatory on the upload path too — an uploaded kit brings its own
             * seed inside the document and the form has no seed box to fill in. Excluding the field
             * drops it from validation entirely, which lets the shared rule set stay whole rather
             * than being picked apart here.
             */
            'seed' => ['exclude_unless:source,generate', ...$this->seedValueRules()],
            /*
             * `max` is in kilobytes and matches the home template's. A kit is seventeen holdings of
             * four small values; anything approaching this is not one.
             */
            'kit' => ['required_if:source,upload', 'file', 'mimes:json,txt', 'max:64'],
        ];
    }

    /**
     * Get the messages for the rules above.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->kitMessages(),
            'seed.required' => __('Choose a seed to draw the kit from.'),
            'kit.required_if' => __('Upload a kit document, or draw one from a seed instead.'),
            'kit.mimes' => __('A kit is a JSON document.'),
        ];
    }
}
