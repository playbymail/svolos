<?php

namespace App\Concerns;

use App\Models\Game;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait GameValidationRules
{
    /**
     * Get the validation rules used to validate a game's name.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function gameNameRules(?int $gameId = null): array
    {
        return ['required', 'string', 'max:255', $this->uniqueGameRule('name', $gameId)];
    }

    /**
     * Get the validation rules used to validate a game's short name.
     *
     * **The order of these rules matters and is the whole rule.** `normalizedShortName()` has already
     * uppercased the input by the time any of this runs (see `prepareForValidation()` on the requests
     * that use this trait), so the character class is deliberately `[A-Z0-9-]` with no lowercase in it:
     *
     * - `run-1` arrives here as `RUN-1`, matches, and is stored uppercased;
     * - `run 1` arrives here as `RUN 1` and is **rejected**, because a space is not in the class.
     *
     * Uppercasing after the pattern check would accept `run 1` too, and putting `a-z` in the class would
     * make the uppercasing invisible. The regex is anchored at both ends so a valid fragment inside an
     * invalid value cannot pass.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function gameShortNameRules(?int $gameId = null): array
    {
        return [
            'required',
            'string',
            'max:'.Game::SHORT_NAME_MAX_LENGTH,
            'regex:/^[A-Z0-9-]+$/',
            $this->uniqueGameRule('short_name', $gameId),
        ];
    }

    /**
     * Get the error messages for a game's own fields.
     *
     * The short name's message names the three things that *are* allowed rather than reporting a failed
     * pattern, because the pattern is not something an administrator can be expected to read. It says
     * nothing about case, on purpose: case is not a mistake the administrator can make, since the value
     * is uppercased before it is checked.
     *
     * @return array<string, string>
     */
    protected function gameMessages(): array
    {
        return [
            'short_name.regex' => __('The short name may only contain letters, numbers and hyphens.'),
            'name.unique' => __('Another game already has that name.'),
            'short_name.unique' => __('Another game already has that short name.'),
        ];
    }

    /**
     * Fold a submitted short name into the form the rules and the column expect.
     *
     * This runs in `prepareForValidation()`, which is *before* the rules, so the uppercasing is part of
     * what gets validated and part of what gets stored — there is no second normalisation step on the
     * way to the database that could disagree with the one the rules saw.
     */
    protected function normalizedShortName(string $shortName): string
    {
        return mb_strtoupper($shortName);
    }

    /**
     * Build the uniqueness rule for one of a game's unique columns.
     *
     * A null `$gameId` is the *stricter* branch: the rule simply stops ignoring the current row, so a
     * request that somehow arrived without a game to update is rejected rather than let through. That
     * is the same shape as `ProfileValidationRules::emailRules()`.
     */
    private function uniqueGameRule(string $column, ?int $gameId): Unique
    {
        $rule = Rule::unique(Game::class, $column);

        return $gameId === null ? $rule : $rule->ignore($gameId);
    }
}
