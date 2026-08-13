<?php

namespace App\Http\Controllers;

use App\Actions\Impersonation\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Starting and stopping impersonation — an administrator seeing the application as somebody else.
 *
 * The two actions deliberately sit on **different sides of the administrator boundary**, which is
 * why they are one controller registered by two routes rather than a controller in `Admin\`:
 *
 * - `store()` is inside the `/admin` group, so only a verified administrator can start;
 * - `destroy()` is on `auth` alone, because by the time it is called the session is a **member**.
 *   Behind `admin` it would 403 the very request that ends the impersonation, and behind `verified`
 *   an unverified target would strand the administrator on the verification notice with no way
 *   back. There is no route back to your own account that the impersonated account cannot take.
 *
 * Who may be impersonated is decided here, in `store()`. Three accounts are refused:
 *
 * - **yourself**, which would write an `impersonator_id` pointing at the session's own user and turn
 *   an ordinary session into one that reads as impersonated for no reason;
 * - **an agent.** There is no person in there to see the application as. Impersonation exists to
 *   reproduce what somebody else is looking at, and an agent looks at nothing — it holds a token and
 *   calls `api/*`. What impersonating one would actually do is let a person submit orders that the
 *   engine attributes to an agent's seat, with the banner as the only record. See
 *   `.ai/rules/agents.md`;
 * - **another administrator.** This is the one that matters. Impersonation is meant to reach the
 *   member accounts an administrator already has power over — deleting them, changing their role —
 *   so it hands out nothing new. Reaching a *peer* would let any administrator act as any other
 *   with no trace in `users` that they did, which is a lateral move between equals rather than the
 *   downward one the feature is for. `EnsureUserIsAdmin` refuses impersonated sessions as well, so
 *   the guarantee survives a target being promoted while somebody is inside their account.
 */
class ImpersonationController extends Controller
{
    /**
     * Start impersonating an account.
     *
     * The redirect goes to the dashboard rather than back to the accounts screen because the
     * session is no longer an administrator's — `/admin` would 403 the moment it landed.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $impersonator = $this->authenticatedUser($request);

        abort_if($user->is($impersonator), 403);
        abort_if($user->isAdmin(), 403);
        abort_if($user->isAgent(), 403);

        ImpersonationSession::start($request, $impersonator, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You are now signed in as :name.', ['name' => $user->name]),
        ]);

        return to_route('dashboard');
    }

    /**
     * Stop impersonating and return to your own account.
     *
     * A request that is not impersonating is a 403 rather than a quiet redirect: this route only
     * ever exists to undo something, and reporting "you are back in your own account" to a session
     * that was never anywhere else would be telling the user something that did not happen.
     *
     * A null return from `stop()` means there was no administrator left to return to — deleted, or
     * demoted mid-impersonation — and the session has been abandoned. One message covers both,
     * because the two are the same fact from the user's side: the account they were driving from is
     * no longer one they can be signed back into.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless(ImpersonationSession::isActive($request), 403);

        $impersonator = ImpersonationSession::stop($request);

        if (! $impersonator instanceof User) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('The administrator account you started from is no longer available. Please sign in again.'),
            ]);

            return to_route('login');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You are signed in as :name again.', ['name' => $impersonator->name]),
        ]);

        return to_route('admin.users.index');
    }
}
