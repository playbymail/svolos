import type { GameStatus } from '@/types/games';

/**
 * Which badge a game's status is shown in.
 *
 * Shared rather than declared beside each screen that renders a status badge, because a status has one
 * appearance across the application: a paused game should not read as neutral on the dashboard and as
 * a warning on the game's own page. Two copies of this map are two chances for exactly that.
 *
 * `archived` is `destructive` because it is the one status that takes a game out of play — see
 * `App\Models\Game::unarchived()` — not because anything about it has gone wrong.
 */
export const gameStatusVariants: Record<
    GameStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    setup: 'outline',
    active: 'default',
    paused: 'secondary',
    completed: 'secondary',
    archived: 'destructive',
};
