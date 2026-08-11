<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users holding the `admin` application role.
 *
 * Registered under the `admin` alias in `bootstrap/app.php` and always used *after* `auth`, so a
 * guest is redirected to the login page by `auth` rather than being told a `/admin` route exists
 * with a 403. A user who is signed in but is not an administrator gets a 403: they are
 * authenticated, so there is nothing to send them to a login page for.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * A null user cannot reach here behind `auth`, and `?->isAdmin()` fails closed if one ever
     * did — `null` is not `true`, so the request is forbidden rather than let through.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin() === true, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
