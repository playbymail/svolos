<?php

namespace App\Actions\Agents;

use App\Models\AgentCredential;
use App\Models\GameSeat;
use App\Models\User;

/**
 * Mints an agent's bearer token. The only place one is ever generated.
 *
 * Keeping this in one readable place is what keeps the token's security properties checkable:
 *
 * - the plain text exists only as a local in `handle()` and in whatever the caller does with the
 *   return value; the column gets `AgentCredential::hashToken()` of it and nothing else;
 * - **minting therefore rotates.** The row is upserted on the seat, so the previous hash is
 *   overwritten and the previously issued token stops working. That is not a limitation to design
 *   around — it is the only behaviour available once the plain text is unrecoverable, and it means a
 *   token pasted into the wrong terminal is revoked by minting another.
 *
 * `last_used_at` is cleared along with it: the new token has not been used, and carrying the old
 * one's timestamp forward would make a fresh credential look live before any agent had touched it.
 */
class IssueAgentCredential
{
    /**
     * Issue (or reissue) the credential for a seat and return the plain-text token.
     *
     * The caller must show the return value to the administrator once and then drop it. Nothing can
     * recover it afterwards.
     */
    public function handle(GameSeat $seat, ?User $issuedBy = null): string
    {
        $token = AgentCredential::generateToken();

        /*
         * Attributes are assigned one at a time and the model declares no `#[Fillable]` at all, the
         * same defence `IssueInvitation` uses: `token` and `issued_by_id` must never be able to
         * arrive from request input, and spelling the writes out is what guarantees it.
         */
        $credential = $seat->agentCredential ?? new AgentCredential;

        $credential->game_seat_id = $seat->getKey();
        $credential->token = AgentCredential::hashToken($token);
        $credential->issued_by_id = $issuedBy?->id;
        $credential->last_used_at = null;
        $credential->save();

        $seat->setRelation('agentCredential', $credential);

        return $token;
    }
}
