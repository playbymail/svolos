<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to people who run at least one game, without naming which.
 *
 * Registered under the `runs-a-game` alias in `bootstrap/app.php` and always used *after* `auth`, so
 * a guest is redirected to the login page rather than being told the route exists with a 403.
 *
 * ## Why there are two gamemaster gates and not one
 *
 * `EnsureUserIsGamemaster` asks whether somebody runs **the game in the URL**, which is the right
 * question for every screen about one game. A kit template belongs to a *person* rather than to a
 * game — it is written once and used at as many games as its author likes — so `/gamemaster/kit-templates`
 * has no `{game}` segment for that gate to read, and it would 403 everybody: its first check is
 * `abort_unless($game instanceof Game)`, which fails closed exactly as designed.
 *
 * So this asks the weaker question the library actually needs: does this account run anything at
 * all. It is a gate on the *area*, not on any row — **ownership of a particular kit is a separate
 * check**, made in `Gamemaster\KitTemplateController` against `user_id`, because passing this says
 * nothing about whose document you are looking at.
 *
 * ## This reads a seat and nothing else
 *
 * The same standing rule its sibling carries, and for the same reason (see `.ai/rules/roles.md`): it
 * must never consult `users.role`, `isAdmin()` or `App\Enums\UserRole`. An administrator who runs no
 * game is refused here, and a **retired** gamemaster seat grants nothing — seats are retired rather
 * than deleted, so `is_active` is what says somebody is still running the game.
 *
 * Both of those conditions live in `App\Models\GameSeat::activeGamemaster()` rather than being
 * written out here, because `App\Http\Middleware\HandleInertiaRequests` has to ask the very same
 * question to decide whether the sidebar offers a link to this area. Two copies that drift leave
 * either a link that 403s or a screen a gamemaster cannot find.
 */
class EnsureUserRunsAGame
{
    /**
     * Handle an incoming request.
     *
     * Fails closed on a null user: the lookup runs against the account's own seats, and there are
     * none to run against.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->gameSeats()->activeGamemaster()->exists() === true,
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
