<?php

namespace App\Concerns;

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Closure;
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
     * Get the validation rules used to validate a game's seed.
     *
     * ## The setup-only rule is a validation rule, and the four gamemaster refusals are not
     *
     * Both areas share this, because both may change a seed and neither may change one after the game
     * has started: a seed is the number the run was drawn from, so re-seeding a game that is already
     * being played rewrites the run its turn reports describe. The refusals in
     * `Gamemaster\GameSeatController` are 403s because the value posted is fine and the *requester* may
     * not post it; this one is a rejected field because it is the *game* that is in the wrong state, and
     * the same administrator will be allowed to post the same number the moment it goes back to setup.
     * A message on the field is what says so.
     *
     * `bail` is load-bearing: without it a prohibited seed also fails `required` and the screen shows two
     * messages for one mistake.
     *
     * A null `$game` is the stricter branch, as everywhere else in this trait — with no game to check the
     * status of, the field is prohibited rather than accepted.
     *
     * @return array<int, Closure|ValidationRule|array<mixed>|string>
     */
    protected function gameSeedRules(?Game $game): array
    {
        return [
            'bail',
            Rule::prohibitedIf(fn (): bool => $game?->status !== GameStatus::Setup),
            /*
             * The second door on the same field, and it needs a message of its own rather than a
             * second `prohibitedIf`: two prohibitions would share the one `seed.prohibited` key and
             * whichever fired would report the other's reason. `bail` above means the first failure
             * is the only one reported either way.
             */
            function (string $attribute, mixed $value, Closure $fail) use ($game): void {
                if ($game?->hasGenerationRuns() === true) {
                    $fail(__('This seed has already been generated from. Start the generation over to change it.'));
                }
            },
            ...$this->seedValueRules(),
        ];
    }

    /**
     * Get the validation rules for a seed as a value, with nothing said about when it may be posted.
     *
     * The range lives here so the game's own seed and the seed handed to a generator cannot disagree
     * about what a seed is. When they are posted differs — a game's seed is prohibited outside setup,
     * a generator's seed is refused by the controller before validation ever runs — and that difference
     * is the caller's to state.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function seedValueRules(): array
    {
        return ['required', 'integer', 'between:'.Game::SEED_MIN.','.Game::SEED_MAX];
    }

    /**
     * Get the validation rules used to validate a game's status.
     *
     * Any status may be chosen with two exceptions, and **a game cannot become `Active` until it can be
     * played**: until every generation stage has been accepted, and until every player has somewhere to
     * begin. An active game is one being played, and a game whose cluster does not exist has nowhere for
     * that to happen — so this is refused where a gamemaster or an administrator can see why, rather
     * than discovered later as an empty universe.
     *
     * **The two checks are not redundant, and the second is not the first restated.** Accepting the home
     * stellia stage places every player seated at the time; seating somebody *afterwards* leaves them
     * with no home and no way to get one short of starting the whole world over. The stage check cannot
     * see that — it reads runs — so the second check is the one that catches the case that actually
     * happens.
     *
     * They are ordered stage-first because a world with no cluster has nowhere to put a home: reporting
     * the unplaced players to somebody who has not generated anything would name the symptom.
     *
     * Only `Active` is gated. Archiving a half-built game is ordinary housekeeping, and pausing or
     * completing one is somebody's business but not this rule's.
     *
     * The message names the stage that is missing, because "finish generating" is not an instruction —
     * "the cluster stage has not been accepted yet" is, and "two players have no home stellium yet" is.
     * A null `$game` is the stricter branch, as everywhere else in this trait: with no game to inspect,
     * the status is refused.
     *
     * The rule is a closure rather than a `Rule::prohibitedIf`, because it refuses one *value* of the
     * field rather than the field itself — every other status stays perfectly postable.
     *
     * @return array<int, Closure|ValidationRule|array<mixed>|string>
     */
    protected function gameStatusRules(?Game $game): array
    {
        return [
            'required',
            Rule::enum(GameStatus::class),
            function (string $attribute, mixed $value, Closure $fail) use ($game): void {
                if ($value !== GameStatus::Active->value) {
                    return;
                }

                $unfinished = $game?->firstUnfinishedGenerationStage();

                if ($game === null || $unfinished !== null) {
                    $fail(__('The :stage stage has not been accepted yet, so this game cannot become active.', [
                        'stage' => mb_strtolower(($unfinished ?? GenerationStage::cases()[0])->label()),
                    ]));

                    return;
                }

                $unplaced = $game->playersWithoutHomeStellium();

                if ($unplaced > 0) {
                    $fail(trans_choice(
                        '{1} One player has no home stellium yet, so this game cannot become active.'
                        .'|[2,*] :count players have no home stellium yet, so this game cannot become active.',
                        $unplaced,
                    ));
                }
            },
        ];
    }

    /**
     * Get the error messages for a game's seed.
     *
     * The range is spelled out rather than described, because a seed is copied from somewhere else as
     * often as it is typed, and "between 0 and 4294967295" is what makes a rejected paste diagnosable.
     * The bound is not arbitrary: it is the width of PHP's Mersenne Twister seed — see `Game::SEED_MAX`.
     *
     * @return array<string, string>
     */
    protected function gameSeedMessages(): array
    {
        return [
            'seed.prohibited' => __('The seed can only be changed while the game is in setup.'),
            'seed.between' => __('The seed must be a whole number between :min and :max.', [
                'min' => Game::SEED_MIN,
                'max' => Game::SEED_MAX,
            ]),
            'seed.integer' => __('The seed must be a whole number between :min and :max.', [
                'min' => Game::SEED_MIN,
                'max' => Game::SEED_MAX,
            ]),
        ];
    }

    /**
     * Get the validation rules used to validate the account being given a seat at a game.
     *
     * ## The uniqueness rule counts retired seats, and that is the point of it
     *
     * `Rule::unique(GameSeat::class, 'user_id')->where('game_id', …)` deliberately carries **no**
     * `is_active` condition. Seats are retired, never deleted (see `App\Models\GameSeat`), so an account
     * that has left the game still owns a row — and the way back in is to *reactivate* that row, not to
     * create a second one. Adding `->where('is_active', true)` here would let a second row be attempted,
     * which the unique index on `(game_id, user_id)` would then refuse with a database error instead of a
     * validation message.
     *
     * The screens agree with this rule rather than restating it: the assignable-accounts list on both
     * `admin/games/Show` and `gamemaster/games/Show` excludes every account that already holds a seat,
     * active or retired, so a retired holder is not offered in the first place. This rule is what makes a
     * hand-made post behave the same way.
     *
     * A null `$gameId` is the *stricter* branch, exactly as `uniqueGameRule()` is: the rule stays unique
     * across every game, so a request that arrived without a game to seat somebody at is refused rather
     * than allowed to create an unscoped seat.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function gameSeatUserRules(?int $gameId = null): array
    {
        $unique = Rule::unique(GameSeat::class, 'user_id');

        if ($gameId !== null) {
            $unique->where('game_id', $gameId);
        }

        return ['required', 'integer', Rule::exists(User::class, 'id'), $unique];
    }

    /**
     * Get the validation rules used to pick the **game** a known account is being seated at.
     *
     * The same constraint as `gameSeatUserRules()`, asked from the other end. The roster screens pick an
     * account for a game they already know; the agents screen picks a game for an agent it already knows
     * (`App\Http\Controllers\Admin\AgentSeatController`), so the uniqueness has to be expressed as
     * "one seat per game for this account" rather than the reverse. It is the same unique index either
     * way, and it counts **retired** seats for the same reason — see `gameSeatUserRules()` for the
     * argument in full, which applies here unchanged.
     *
     * A null `$userId` is the stricter branch, matching its sibling: without an account to scope by, the
     * rule refuses any game that has a seat at all rather than creating an unscoped one.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function gameSeatGameRules(?int $userId = null): array
    {
        $unique = Rule::unique(GameSeat::class, 'game_id');

        if ($userId !== null) {
            $unique->where('user_id', $userId);
        }

        return ['required', 'integer', Rule::exists(Game::class, 'id'), $unique];
    }

    /**
     * Get the validation rules used to validate the game role a seat holds.
     *
     * Only the role is ever accepted alongside this. `is_active` is not validated anywhere and never
     * will be — a seat is retired and reactivated through its own two endpoints, so a change of role can
     * never move a seat in or out of the game as a side effect. `GameSeat`'s `#[Fillable]` list leaves
     * `is_active` out for the same reason.
     *
     * Which roles a particular *requester* may hand out is not a validation question: a gamemaster may
     * not demote another gamemaster, and that is an authorisation rule enforced with a 403 in
     * `App\Http\Controllers\Gamemaster\GameSeatController`, not a rejected value.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function gameSeatRoleRules(): array
    {
        return ['required', Rule::enum(GameRole::class)];
    }

    /**
     * Get the error messages for the fields of a seat.
     *
     * The duplicate message names the situation rather than the constraint: somebody who picked an
     * account that has quietly been retired from this game needs to be told to reactivate the seat, not
     * that a unique index rejected them.
     *
     * @return array<string, string>
     */
    protected function gameSeatMessages(): array
    {
        return [
            'user_id.unique' => __('That account already has a seat in this game.'),
            'user_id.exists' => __('That account no longer exists.'),

            /*
             * The same two facts reached from the other end, for the screen that picks a game rather
             * than an account (`gameSeatGameRules()`). The duplicate message is worded identically
             * because it describes the same situation, and somebody who hit it needs to be told to
             * reactivate the existing seat either way.
             */
            'game_id.unique' => __('That account already has a seat in this game.'),
            'game_id.exists' => __('That game no longer exists.'),
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
