<?php

namespace App\Http\Requests\Player;

use App\Models\GameSeat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a player may write about themselves at one game: the name of their empire, and whether they
 * want to be emailed about it.
 *
 * **The absence of `number` is the rule this class exists for**, and it is the same rule
 * `Gamemaster\GameStatusUpdateRequest` enforces by leaving out `name`: the controller fills the seat
 * from `validated()`, and `validated()` returns only keys that were validated, so a posted `number` is
 * dropped on the floor rather than written. An empire number is assigned once by `GameSeat::booted()`
 * and is the name the engine's history knows this player by — nobody renumbers themselves. `role` and
 * `is_active` are absent for the older version of the same reason, and `is_active` could not be
 * written through here anyway since it is out of `#[Fillable]`.
 *
 * The rules are inline rather than in an `App\Concerns\*ValidationRules` trait because there is
 * exactly one caller. The traits elsewhere exist so the administrator's and gamemaster's copies of a
 * shared rule cannot drift; there is no second copy of this one to drift from.
 */
class GameProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `empire_name` is **required**, so the fallback name is a starting point rather than a way to
     * clear the field: a player who wants the dull name can simply save it. Clearing it back to null
     * is not offered, because "I have not chosen yet" is a state a player passes through rather than
     * returns to.
     *
     * Empire names are deliberately **not** unique within a game. Two empires with the same name are
     * confusing rather than broken, they are told apart by their numbers — which *are* unique — and
     * refusing the second one would let an early player take a name somebody else wanted by accident.
     *
     * `email_notifications` is required rather than `sometimes`, and the screen posts a hidden field
     * beside its checkbox to satisfy that: an unticked checkbox posts nothing at all, so `sometimes`
     * would read "turn it off" as "leave it alone" and the box would be impossible to untick.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'empire_name' => ['required', 'string', 'max:'.GameSeat::EMPIRE_NAME_MAX_LENGTH],
            'email_notifications' => ['required', 'boolean'],
        ];
    }
}
