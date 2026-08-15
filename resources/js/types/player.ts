import type { GameStatus } from '@/types/games';

/**
 * One game as its player sees it, as shaped by
 * `App\Http\Controllers\Player\GameController::present()`.
 *
 * Much less than a gamemaster is sent, and the omissions are deliberate: no seed, no roster, no
 * generation state. A player meets a finished world, not the machine that built it.
 *
 * `year` and `quarter` are derived from `turn` on the server by `App\Models\Game::yearAndQuarter()`
 * and sent alongside it rather than computed here, so there is one implementation of the calendar and
 * it is the one with a test around it. Turn 0 is the setup turn — year 0, quarter 0.
 */
export type PlayerGame = {
    id: number;
    name: string;
    short_name: string;
    status: GameStatus;
    status_label: string;
    is_active: boolean;
    turn: number;
    year: number;
    quarter: number;
};

/**
 * The player's own seat at that game — their empire — as shaped by
 * `App\Http\Controllers\Player\GameController::presentSeat()`.
 *
 * `empire_name` is the raw column and is null until the player names their empire;
 * `empire_name_default` is what it is called meanwhile. **Both are sent on purpose.** The form
 * prefills from the default while the page can still tell an unnamed empire from one somebody
 * deliberately named "Game ACME Seat 3", which a single resolved string could not.
 *
 * `number` is the empire number: assigned once when the seat was created, unique within the game, and
 * never reused. It is read-only here — there is no rule under which a player renumbers themselves,
 * and `App\Http\Requests\Player\GameProfileUpdateRequest` does not validate the field.
 */
export type PlayerSeat = {
    id: number;
    number: number;
    empire_name: string | null;
    empire_name_default: string;
    email_notifications: boolean;
};
