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
 */
export type AdminGame = {
    id: number;
    name: string;
    short_name: string;
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
