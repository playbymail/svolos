<?php

namespace App\Http\Middleware;

use App\Enums\GameRole;
use App\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the people running the one game named in its URL.
 *
 * Registered under the `gamemaster` alias in `bootstrap/app.php` and always used *after* `auth`, so
 * a guest is redirected to the login page rather than being told the route exists with a 403. A
 * signed-in account that holds no active gamemaster seat at that game gets the 403.
 *
 * ## This reads a seat and nothing else
 *
 * It must never consult `users.role`, `isAdmin()` or `App\Enums\UserRole`, and
 * `App\Http\Middleware\EnsureUserIsAdmin` must never consult a game or a seat — the two role
 * systems are unrelated and an authorisation check reads exactly one of them (see
 * `.ai/rules/roles.md`). Two consequences follow, and both are deliberate:
 *
 * - an **administrator without a seat is refused here.** Being an administrator says nothing about
 *   game membership, and `/admin/games/{game}` is the screen that answers to `UserRole::Admin`;
 * - a **retired** gamemaster seat grants nothing. Seats are retired rather than deleted, so the row
 *   outlives the person's time in the game and `is_active` is what says they are still in it.
 *
 * Unlike the admin middleware there is no impersonation check. An administrator inside somebody
 * else's session is meant to see what that account sees, and what this gate opens is bounded by the
 * seats that account actually holds — which is the whole point of impersonating them.
 */
class EnsureUserIsGamemaster
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
                ->where('role', GameRole::Gamemaster)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
