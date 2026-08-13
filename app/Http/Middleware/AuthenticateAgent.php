<?php

namespace App\Http\Middleware;

use App\Actions\Agents\AgentSession;
use App\Enums\GameStatus;
use App\Models\AgentCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an `api/*` request from the bearer token in its `Authorization` header.
 *
 * This is the whole of the agent authentication surface. There is no session, no cookie and no login
 * form behind it: the token *is* the credential, and because it is stored hashed, the lookup is a
 * single indexed read of the sha256 of what arrived — which is the reason `AgentCredential::hashToken()`
 * is a plain digest rather than a password hash.
 *
 * ## Why the failures answer differently
 *
 * A missing or unrecognised token is a **401**: the request has not proved who it is, and it gets
 * nothing back that would distinguish "no such token" from "that token was rotated away", because a
 * caller holding neither should learn neither.
 *
 * A recognised token whose seat has been retired, or whose game has been archived, is a **403** and
 * says which. The caller has already proved it holds a live credential, so there is nothing left to
 * leak — and the distinction is the difference between an operator correctly reactivating a seat and
 * an operator pointlessly minting a replacement token for one that was never broken.
 */
class AuthenticateAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        abort_if($token === null || $token === '', 401, __('Provide your agent token as a bearer token.'));

        $credential = AgentCredential::query()
            ->with(['gameSeat.user', 'gameSeat.game'])
            ->firstWhere('token', AgentCredential::hashToken($token));

        abort_unless($credential instanceof AgentCredential, 401, __('That agent token is not valid.'));

        $seat = $credential->gameSeat;

        abort_unless($seat->is_active, 403, __('That seat has been retired.'));
        abort_if($seat->game->status === GameStatus::Archived, 403, __('That game has been archived.'));

        AgentSession::start($request, $credential);

        /*
         * Written before the request is handled rather than after, so a call that throws still leaves
         * evidence the token was used. This is the only column an agent's own traffic writes, and it
         * is what an administrator reads on the agents screen to tell a live agent from an idle one.
         */
        $credential->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
