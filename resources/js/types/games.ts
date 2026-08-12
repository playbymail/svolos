/**
 * Mirrors the `App\Enums\GameStatus` backed enum, which serialises to its value.
 *
 * Stored, not derived: a paused game and an active one differ by a decision somebody made rather than
 * by a timestamp. `archived` is the only case with behaviour attached — `Game::unarchived()` excludes it.
 */
export type GameStatus =
    'setup' | 'active' | 'paused' | 'completed' | 'archived';

/**
 * Mirrors the `App\Enums\GameRole` backed enum, which serialises to its value.
 *
 * **This is a game role and carries no application permissions.** It is scoped to the one game the seat
 * belongs to: a `gamemaster` is not an administrator, and it has nothing to do with `UserRole`, which is
 * what gates `/admin`. The two are deliberately unrelated systems — see `.ai/rules/roles.md`.
 */
export type GameRole = 'player' | 'gamemaster';

/**
 * A selectable status, labelled on the server by `App\Enums\GameStatus::label()`.
 */
export type GameStatusOption = {
    value: GameStatus;
    label: string;
};

/**
 * A selectable game role, labelled on the server by `App\Enums\GameRole::label()`.
 */
export type GameRoleOption = {
    value: GameRole;
    label: string;
};

/**
 * One game, as shaped by `App\Http\Controllers\Admin\GameController::present()`.
 *
 * `seats_count` and `active_seats_count` are separate numbers on purpose: the difference between them is
 * how many people have left the game, which is worth seeing without opening it. `active_seats_count`
 * comes from the dedicated `Game::activeSeats()` relation rather than a `withCount` closure alias.
 *
 * `seed` is the number the game's randomness is drawn from, assigned at creation. `can_change_seed` says
 * whether it may still be set: only while the game is in setup **and** nothing has been generated from
 * it yet. `seed_lock_reason` is the sentence explaining a false one, and it comes from the server
 * because the two reasons are different sentences — a game that has left setup and a game whose cluster
 * already exists are not locked for the same reason. Both are **presentation**: the server refuses a
 * late seed whatever the screen renders.
 */
export type AdminGame = {
    id: number;
    name: string;
    short_name: string;
    seed: number;
    can_change_seed: boolean;
    seed_lock_reason: string | null;
    status: GameStatus;
    status_label: string;
    seats_count: number;
    active_seats_count: number;
    created_at: string;
    created_at_diff: string | null;
};

/**
 * One seat on a game's roster, as shaped by
 * `App\Http\Controllers\Admin\GameController::presentSeat()`.
 *
 * `is_active` is false for a **retired** seat. Retired seats stay on the roster because they are never
 * deleted — engine history keeps referring to them — so the screen offers reactivation rather than a
 * second seat, and there is deliberately no delete control to render.
 */
export type AdminGameSeat = {
    id: number;
    user_id: number;
    user_name: string;
    user_email: string;
    role: GameRole;
    role_label: string;
    is_active: boolean;
    created_at_diff: string | null;
};

/**
 * The fields `GameSeatRoleForm.svelte` needs of a seat.
 *
 * Named separately so the one picker serves both rosters: the administrator's rows are `AdminGameSeat`
 * and the gamemaster's are `GamemasterGameSeat`, and neither needs to know about the other. The form
 * action itself is passed in, which is what keeps the component from importing one area's controller.
 */
export type GameSeatRoleTarget = Pick<
    AdminGameSeat,
    'id' | 'role' | 'user_name'
>;

/**
 * The fields `GameSeedForm.svelte` needs of a game.
 *
 * Named separately for the same reason `GameSeatRoleTarget` is: the one form serves the administrator's
 * screen and the gamemaster's, and neither row shape needs to know about the other. The form action is
 * passed in, which is what keeps the component from importing one area's controller.
 */
export type GameSeedTarget = Pick<
    AdminGame,
    'seed' | 'can_change_seed' | 'seed_lock_reason'
>;

/**
 * One game as its **gamemaster** sees it, shaped by
 * `App\Http\Controllers\Gamemaster\GameController::present()`.
 *
 * The same fields as `AdminGame`, but `name` and `short_name` arrive here to be *displayed*: a
 * gamemaster may move a game between statuses and may not rename it, and the server enforces that by
 * validating nothing else — see `App\Http\Requests\Gamemaster\GameStatusUpdateRequest`.
 *
 * The seed is the opposite case: a gamemaster may set it as well as see it, on exactly the same terms an
 * administrator can, so `can_change_seed` here means what it means on `AdminGame` — the game is still in
 * setup.
 */
export type GamemasterGame = {
    id: number;
    name: string;
    short_name: string;
    seed: number;
    can_change_seed: boolean;
    seed_lock_reason: string | null;
    status: GameStatus;
    status_label: string;
    seats_count: number;
    active_seats_count: number;
    created_at: string;
    created_at_diff: string | null;
};

/**
 * One seat on a roster as its game's gamemaster sees it, shaped by
 * `App\Http\Controllers\Gamemaster\GameController::presentSeat()`.
 *
 * The three extra flags are what a gamemaster may do to this row, and they are **presentation only** —
 * `Gamemaster\GameSeatController` refuses the same things with a 403 whatever the screen renders:
 *
 * - `is_self` — the viewer's own seat, labelled as such rather than silently missing its controls;
 * - `can_retire` — false for their own seat (a gamemaster does not retire themselves) and for a seat
 *   that is already retired;
 * - `can_change_role` — whether to render the role picker at all. False for a seat that already holds
 *   `gamemaster`, because only an administrator can take the role back off, and false for a
 *   **retired** seat, because that role is a fact about the game's history rather than a live
 *   decision. One flag rather than two: it answers one question, and two flags read together are two
 *   things to keep in step.
 */
export type GamemasterGameSeat = AdminGameSeat & {
    is_self: boolean;
    can_retire: boolean;
    can_change_role: boolean;
};

/**
 * An account that can still be given a seat at the game being shown.
 *
 * The server excludes every account that already holds a seat, **retired ones included**: a retired seat
 * still occupies its account's place in the unique index on `(game_id, user_id)`, so the only way a
 * departed account returns is by reactivating the seat already on the roster.
 */
export type AssignableAccount = {
    id: number;
    name: string;
    email: string;
};
