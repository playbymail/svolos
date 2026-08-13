<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Agents\IssueAgentCredential;
use App\Http\Controllers\Controller;
use App\Models\AgentCredential;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Issuing and revoking the bearer token a seat's agent authenticates with.
 *
 * Both actions sit behind `admin` while *seating* an agent does not, and the split is deliberate: a
 * roster is one game's business and a gamemaster runs it, but a token is a credential for an account,
 * and handing one out is not a decision about a roster.
 *
 * The routes are scoped, so `{seat}` resolves through the agent's own seats. A seat id belonging to
 * somebody else 404s here rather than being minted a token through the wrong agent's URL — the same
 * reason the game seat routes are scoped to their game.
 */
class AgentCredentialController extends Controller
{
    /**
     * Mint a token for a seat, replacing any token that seat already had.
     *
     * The plain text is put in the flash bag and nowhere else. It survives exactly one redirect, is
     * rendered once, and is gone on the next request — which is the whole of "shown once", with no
     * dismissal state to keep and nothing to clean up if the administrator closes the tab. It is
     * never written to the database, never logged, and cannot be recovered afterwards.
     */
    public function store(
        Request $request,
        User $user,
        GameSeat $gameSeat,
        IssueAgentCredential $issueCredential,
    ): RedirectResponse {
        $this->abortUnlessAgentSeat($user, $gameSeat);

        /*
         * A retired seat gets no token. `AuthenticateAgent` refuses one anyway, so this is not the
         * only thing standing between a retired seat and a working agent — but issuing a credential
         * that is guaranteed to be rejected would be telling the administrator they had done
         * something when they had not.
         */
        abort_unless($gameSeat->is_active, 403);

        $token = $issueCredential->handle($gameSeat, $this->authenticatedUser($request));

        Inertia::flash('agentToken', [
            'token' => $token,
            'agent' => $user->name,
            'game' => $gameSeat->game->name,
        ]);

        return to_route('admin.agents.show', $user);
    }

    /**
     * Revoke a seat's token without issuing a replacement.
     *
     * Minting again is the ordinary way to invalidate a token, because it is what an administrator
     * wants when a secret has leaked but the agent should keep playing. This is the other case: stop
     * the agent now, and decide later whether it comes back.
     */
    public function destroy(User $user, GameSeat $gameSeat): RedirectResponse
    {
        $this->abortUnlessAgentSeat($user, $gameSeat);

        $credential = $gameSeat->agentCredential;

        abort_unless($credential instanceof AgentCredential, 404);

        $credential->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name can no longer act in :game.', [
                'name' => $user->name,
                'game' => $gameSeat->game->name,
            ]),
        ]);

        return to_route('admin.agents.show', $user);
    }

    /**
     * Refuse a seat that is not an agent's.
     *
     * The account check is what keeps this screen from becoming a way to mint a token for a *person's*
     * seat. That combination is not nonsense — it is how a delegated agent would work, an automation
     * driving somebody's seat with their knowledge — but it is a feature with its own consent
     * question to answer, not something to fall out of a URL an administrator can type today. The
     * schema is ready for it; this controller is not the place it arrives.
     *
     * A 404 rather than a 403, matching `AgentController::show()`: the seat is not in the collection
     * this URL addresses.
     */
    private function abortUnlessAgentSeat(User $user, GameSeat $gameSeat): void
    {
        abort_unless($user->isAgent(), 404);
        abort_unless($gameSeat->user_id === $user->getKey(), 404);
    }
}
