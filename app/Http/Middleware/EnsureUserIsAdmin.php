<?php

namespace App\Http\Middleware;

use App\Actions\Impersonation\ImpersonationSession;
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
 *
 * An **impersonated** session is refused whatever role it holds. Only members can be impersonated
 * (`ImpersonationController::store()` refuses an administrator), so today the role check would
 * already have caught it — but "today" is the whole problem: an account can be promoted while
 * somebody is inside it, and that would silently turn a borrowed member session into a full
 * administrator one. This is the check that makes "impersonation never reaches `/admin`" a property
 * of the boundary itself rather than a consequence of a rule enforced one controller away.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * A null user cannot reach here behind `auth`, and `?->isAdmin()` fails closed if one ever
     * did — `null` is not `true`, so the request is forbidden rather than let through. That check
     * stays first so a route mounted without `auth` (and therefore possibly without a session)
     * is refused before anything asks the session a question.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin() === true, Response::HTTP_FORBIDDEN);
        abort_if(ImpersonationSession::isActive($request), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
