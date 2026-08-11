<?php

namespace App\Actions\Impersonation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place that knows how an impersonated session is written, read and unwound.
 *
 * Impersonation is a single key in the session — the id of the administrator who started it — laid
 * on top of an ordinary authenticated session for the target account. Everything else follows from
 * that: the request really *is* the target account (`$request->user()` is them, their policies and
 * their data apply), and the only trace of who is actually driving is `SESSION_KEY`.
 *
 * That key is a **privilege record, not a credential**: it is what `stop()` reads to put the
 * administrator back, so anything that can write it can hand itself an administrator's account.
 * It is therefore only ever written here, from a request that has already passed
 * `EnsureUserIsAdmin`, and it is never accepted from request input.
 *
 * Four callers share it and they must agree, which is why the key does not appear anywhere else:
 * `ImpersonationController` (start and stop), `EnsureUserIsAdmin` (an impersonated session can
 * never reach `/admin`), and `HandleInertiaRequests` (the banner that tells the administrator they
 * are not themselves).
 */
final class ImpersonationSession
{
    /**
     * The session key holding the id of the administrator behind an impersonated session.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Sign the current browser in as `$target`, remembering who did it.
     *
     * `Auth::login()` migrates the session, which changes the identifier the browser holds but keeps
     * the session's data, so the key is written afterwards to make the order obvious rather than
     * because it would otherwise be lost. Migrating is what stops the impersonated session from
     * being the *same* session row the administrator arrived on: the row now belongs to the target
     * account, so an administrator who impersonates and then walks away has not left a session row
     * that reads as their own.
     *
     * The caller is responsible for refusing the targets that must not be impersonated — see
     * `ImpersonationController::store()`. This method is deliberately not a second opinion on that:
     * one place to read the rules is worth more than two places that could disagree.
     */
    public static function start(Request $request, User $impersonator, User $target): void
    {
        Auth::login($target);

        $request->session()->put(self::SESSION_KEY, $impersonator->getKey());
    }

    /**
     * Put the administrator back into their own account, and return them.
     *
     * The key is `pull()`ed rather than read and then forgotten, so the session cannot come out of
     * this method still marked as impersonating whichever way the account lookup goes.
     *
     * A null return means the administrator's account no longer exists — another administrator can
     * delete it while this browser is impersonating, because the session row belongs to the *target*
     * account by then and so is not among the rows that deletion removes. There is nobody to go back
     * to, so the session is signed out entirely instead: leaving the browser signed in as the target
     * would turn a deleted administrator into a permanent anonymous foothold in somebody else's
     * account.
     */
    public static function stop(Request $request): ?User
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        $impersonator = is_int($impersonatorId) || is_string($impersonatorId)
            ? User::query()->find($impersonatorId)
            : null;

        if (! $impersonator instanceof User) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::login($impersonator);

        return $impersonator;
    }

    /**
     * Determine whether this request is being made from an impersonated session.
     *
     * `hasSession()` is checked first because this is called from `EnsureUserIsAdmin`, which can be
     * mounted on a route outside the `web` group where there is no session to ask.
     */
    public static function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /**
     * Get the administrator behind an impersonated session, if there is one.
     *
     * Returns null on an ordinary session, so the shared Inertia props cost no query for the
     * requests that are not impersonated — which is all of them, nearly all of the time.
     */
    public static function impersonator(Request $request): ?User
    {
        if (! self::isActive($request)) {
            return null;
        }

        $impersonatorId = $request->session()->get(self::SESSION_KEY);

        return is_int($impersonatorId) || is_string($impersonatorId)
            ? User::query()->find($impersonatorId)
            : null;
    }
}
