<?php

namespace App\Actions\Agents;

use App\Models\AgentCredential;
use App\Models\GameSeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place that knows how an agent-authenticated request carries its credential.
 *
 * An `api/*` request has no session — the `api` group is registered without `web`, so there are no
 * cookies, no CSRF token and nothing persisted between calls. The credential resolved from the
 * `Authorization` header lives on the request itself for the length of that one request, and this
 * class is where the attribute key is written and read so it appears in no caller. That containment
 * is the same one `App\Actions\Impersonation\ImpersonationSession` applies to its session key.
 *
 * **The principal is the seat, not the account.** `Auth::setUser()` is called so anything reading
 * `$request->user()` sees a real account, but game code should ask for `seat()`: authorisation in
 * this application is "does this seat control this thing", and a seat is what an order will be
 * attributed to. Reading the account instead would be a question one game wider than the token is
 * scoped to, and it is the reading that breaks first when an agent is delegated a person's seat.
 */
final class AgentSession
{
    /**
     * The request attribute holding the credential this request authenticated with.
     */
    private const REQUEST_KEY = 'agent_credential';

    /**
     * Authenticate this request as the seat the credential belongs to.
     *
     * `Auth::setUser()` rather than `Auth::login()`: there is no session to write, and logging in
     * would start one. The caller is responsible for having established that the credential is
     * usable — see `App\Http\Middleware\AuthenticateAgent`, which is the only caller.
     */
    public static function start(Request $request, AgentCredential $credential): void
    {
        $request->attributes->set(self::REQUEST_KEY, $credential);

        Auth::setUser($credential->gameSeat->user);
    }

    /**
     * Get the credential this request authenticated with, or null on a request that did not.
     */
    public static function credential(Request $request): ?AgentCredential
    {
        $credential = $request->attributes->get(self::REQUEST_KEY);

        return $credential instanceof AgentCredential ? $credential : null;
    }

    /**
     * Get the seat this request is acting as, or null on a request that is not an agent's.
     *
     * This is what game code should read. A null means the request never cleared
     * `AuthenticateAgent`, so callers behind that middleware can treat a null as impossible without
     * asserting it — the nullable return is what keeps a caller that is *not* behind it honest.
     */
    public static function seat(Request $request): ?GameSeat
    {
        return self::credential($request)?->gameSeat;
    }
}
