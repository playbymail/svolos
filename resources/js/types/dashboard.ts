import type { GameStatus } from '@/types/games';

/**
 * One game the signed-in account holds an **active** seat in, as shaped by
 * `App\Http\Controllers\DashboardController::present()`.
 *
 * `is_archived` is what makes the show/hide toggle instant. Archived games ship in the payload with
 * every other game rather than being filtered out on the server, so revealing them is a change of
 * local state and never a round trip. There is deliberately no seat count here: this screen answers
 * which games the account is in, not who else is in them.
 */
export type DashboardGame = {
    id: number;
    name: string;
    short_name: string;
    status: GameStatus;
    status_label: string;
    is_archived: boolean;
};
