<?php

namespace App\Http\Controllers\Api;

use App\Actions\Agents\AgentSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentIdentityResource;
use App\Models\GameSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tells an agent who its token makes it.
 *
 * This is the whole of the agent API for now, and it exists so the credential path can be exercised
 * end to end before there is anything to command: an agent that can call this successfully has a
 * working token, a live seat and a game it may act in. The endpoints that accept orders come with the
 * engine, and when they do, the rule they must hold to is that **only an entity accepts an order** —
 * with the check living in a domain action both this surface and the browser call, never in a
 * controller. See `.ai/rules/agents.md`.
 */
class AgentIdentityController extends Controller
{
    /**
     * Describe the seat this request authenticated as.
     *
     * The seat comes from `AgentSession` rather than from `$request->user()`: the token is scoped to
     * one seat, and an account could hold seats at several games. Asking the account which game it is
     * in would be a question wider than the credential.
     */
    public function __invoke(Request $request): JsonResource
    {
        $seat = AgentSession::seat($request);

        /*
         * Unreachable behind `AuthenticateAgent`, which 401s before it gets here. It is an abort
         * rather than an assertion so that mounting this route without that middleware fails as a
         * refusal instead of a type error — and so PHPStan gets its narrowing honestly.
         */
        abort_unless($seat instanceof GameSeat, 401);

        return new AgentIdentityResource($seat);
    }
}
