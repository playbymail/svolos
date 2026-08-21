<?php

namespace App\Http\Middleware;

use App\Enums\GameRole;
use App\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the people playing the one game named in its URL.
 *
 * Registered under the `player` alias in `bootstrap/app.php` and always used *after* `auth`, so a
 * guest is redirected to the login page rather than being told the route exists with a 403. A
 * signed-in account that holds no active player seat at that game gets the 403.
 *
 * ## This reads a seat and nothing else
 *
 * The sibling of `EnsureUserIsGamemaster`, and it must be kept one: it may never consult
 * `users.role`, `isAdmin()` or `App\Enums\UserRole`, because the two role systems are unrelated and
 * an authorisation check reads exactly one of them (see `.ai/rules/roles.md`). Three consequences
 * follow, and all three are deliberate:
 *
 * - an **administrator without a seat is refused here**, exactly as they are on the gamemaster's
 *   screen. Being an administrator says nothing about game membership;
 * - a **retired** player seat grants nothing. Seats are retired rather than deleted, so the row
 *   outlives the person's time in the game and `is_active` is what says they are still in it;
 * - a **gamemaster is refused too**, which is the one that looks like an oversight and is not. This
 *   gate opens a screen about an empire — its number, its name, where in the cluster it begins, what
 *   it is holding — and a gamemaster has none of those. `GenerateHomeStellia` places players and not
 *   gamemasters, `GenerateUnits` gives players a colony and a ship and gamemasters nothing, and
 *   there is no empire number for a seat that is not playing. Letting a gamemaster through would
 *   mean a screen made almost entirely of empty states, in service of a question they already have
 *   `/gamemaster/games/{game}` to answer better. A gamemaster who wants to see a player's view is
 *   really asking to see a *particular* player's view, which is a different feature.
 *
 * Unlike the admin middleware there is no impersonation check, for the same reason the gamemaster
 * gate has none: an administrator inside somebody else's session is meant to see what that account
 * sees, and what this gate opens is bounded by the seats that account actually holds.
 */
class EnsureUserIsPlayer
{
    /**
     * Handle an incoming request.
     *
     * Both checks fail closed. A route mounted without a `{game}` parameter — or one whose binding
     * resolved to something else — is refused before any seat is looked up, and a null user makes
     * the seat lookup match `user_id = null`, which no row ever does.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $game = $request->route('game');

        abort_unless($game instanceof Game, Response::HTTP_FORBIDDEN);

        abort_unless(
            $game->activeSeats()
                ->where('user_id', $request->user()?->getKey())
                ->where('role', GameRole::Player)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
