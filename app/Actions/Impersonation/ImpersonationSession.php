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
     * A null return means there is no administrator left to go back to — the account was deleted, or
     * demoted to a member, by a second administrator while this browser was impersonating. Either is
     * reachable: the session row belongs to the *target* account by then, so it is not among the rows
     * that deleting the administrator removes, and nothing about a role change touches it at all.
     * Both take the same way out, `abandon()`.
     */
    public static function stop(Request $request): ?User
    {
        $impersonator = self::findImpersonator($request->session()->pull(self::SESSION_KEY));

        if (! $impersonator instanceof User) {
            self::abandon($request);

            return null;
        }

        Auth::login($impersonator);

        return $impersonator;
    }

    /**
     * End an impersonation that cannot be unwound, by signing the browser out entirely.
     *
     * This is the answer whenever the administrator behind the session is gone — deleted, or no
     * longer an administrator — because the alternatives are both worse than a sign-in prompt.
     * Leaving the browser signed in as the target turns a removed administrator into an anonymous
     * foothold in somebody else's account, and signing a demoted account back in hands the session
     * to somebody the impersonation was never authorised for. Losing the session costs one login;
     * the other two cost an account.
     *
     * `invalidate()` flushes the impersonation key along with everything else, and the token is
     * regenerated so the login form the user lands on is not posting a CSRF token minted for a
     * session that no longer exists.
     */
    public static function abandon(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
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
     * Get the administrator behind an impersonated session, if there is still one.
     *
     * Returns null on an ordinary session, so the shared Inertia props cost no query for the
     * requests that are not impersonated — which is all of them, nearly all of the time.
     *
     * It also returns null on an impersonated session whose administrator has gone, so callers get
     * one answer to "is there an account to go back to?" rather than each deciding for themselves.
     * Note what that means for the banner: a null impersonator does **not** mean the session is not
     * impersonating. Ask `isActive()` for that — see `HandleInertiaRequests`.
     */
    public static function impersonator(Request $request): ?User
    {
        if (! self::isActive($request)) {
            return null;
        }

        return self::findImpersonator($request->session()->get(self::SESSION_KEY));
    }

    /**
     * Resolve a session value into the administrator it names, or null if it names nobody usable.
     *
     * The role is re-checked on every lookup rather than trusted from when the key was written: the
     * key records that an administrator started this, not that they still are one, and a second
     * administrator can demote them at any point in between. Treating a demoted account as absent is
     * what keeps `stop()` from signing a session back in as somebody who is no longer allowed there.
     */
    private static function findImpersonator(mixed $impersonatorId): ?User
    {
        $impersonator = is_int($impersonatorId) || is_string($impersonatorId)
            ? User::query()->find($impersonatorId)
            : null;

        return $impersonator instanceof User && $impersonator->isAdmin() ? $impersonator : null;
    }
}
