<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\InvitationLinkProblem;
use App\Http\Requests\InvitationAcceptanceRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public half of the invitation flow: the only way an account is created.
 *
 * Both actions are guest-only. Accepting an invitation creates a *new* account and signs it in, so a
 * request that already has an authenticated user is on the wrong screen by definition — the `guest`
 * middleware sends them to their dashboard rather than letting them mint a second account, or worse,
 * consume somebody else's invitation from inside their own session.
 *
 * A token that cannot be used renders `invitations/Invalid` with the reason, never a generic error.
 * See `App\Enums\InvitationLinkProblem`.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly CreateNewUser $createNewUser) {}

    /**
     * Show the acceptance form for an invitation token.
     */
    public function show(string $token): Response
    {
        $invitation = $this->pendingInvitation($token);

        if (! $invitation instanceof Invitation) {
            return $this->invalidLink($token);
        }

        return Inertia::render('invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'roleLabel' => $invitation->role->label(),
            'expiresAtDiff' => $invitation->expires_at->diffForHumans(),
        ]);
    }

    /**
     * Accept an invitation: create the account, sign it in, and mark the invitation used.
     *
     * Four things here are load-bearing:
     *
     * - **The email address comes from the invitation, never from the request.** The form shows it
     *   read-only as a courtesy; the server ignores whatever was posted, so editing the field in the
     *   browser cannot redirect an invitation to a different mailbox.
     * - **The account is created by `CreateNewUser`** — the same action Fortify's registration would
     *   have used — so the password and profile rules stay in exactly one place.
     * - **The role is assigned explicitly, after creation.** `role` is not mass-assignable, which is
     *   precisely what stops a posted `role=admin` from riding in through `CreateNewUser`. The
     *   invitation is the only thing that decides it. See `.ai/rules/roles.md`.
     * - **The email address is left unverified.** Clicking a link in an email proves that somebody
     *   read the mailbox, not that the person filling in this form controls it, so the new account
     *   completes the ordinary verification flow. `Registered` fires, which is what sends the
     *   verification email, and `verified` keeps the account out of the application until it is done.
     */
    public function store(InvitationAcceptanceRequest $request, string $token): RedirectResponse|Response
    {
        $invitation = $this->pendingInvitation($token);

        if (! $invitation instanceof Invitation) {
            return $this->invalidLink($token);
        }

        /*
         * The account, its role and the invitation's `accepted_at` are one change in three writes, so
         * they land together or not at all: a half-applied acceptance would leave a live invitation
         * pointing at an account that already exists.
         */
        $user = DB::transaction(function () use ($request, $invitation): User {
            $user = $this->createNewUser->create([
                'name' => $request->string('name')->toString(),
                'email' => $invitation->email,
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
            ]);

            $user->role = $invitation->role;
            $user->save();

            $invitation->accepted_at = now();
            $invitation->save();

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Welcome. Please confirm your email address to finish setting up your account.'),
        ]);

        return to_route('dashboard');
    }

    /**
     * Find the invitation a token belongs to, if that invitation can still be accepted.
     *
     * The lookup is by hash: the plain token is never stored, so it is hashed on the way in and
     * compared against the column. A token for an invitation that exists but is expired or already
     * used resolves to `null` here and is classified by `invalidLink()`.
     */
    private function pendingInvitation(string $token): ?Invitation
    {
        $invitation = Invitation::query()->firstWhere('token', Invitation::hashToken($token));

        return $invitation instanceof Invitation && $invitation->isPending() ? $invitation : null;
    }

    /**
     * Render the page that says why a link cannot be used.
     *
     * The three reasons stay separate all the way to the screen because they have three different
     * remedies. The invitation is looked up a second time here rather than threaded through: this
     * path only runs when the request is already going to fail, and one extra query buys a `show()`
     * and a `store()` that read the same way.
     */
    private function invalidLink(string $token): Response
    {
        $invitation = Invitation::query()->firstWhere('token', Invitation::hashToken($token));

        $problem = match (true) {
            ! $invitation instanceof Invitation => InvitationLinkProblem::Unknown,
            $invitation->isAccepted() => InvitationLinkProblem::Accepted,
            default => InvitationLinkProblem::Expired,
        };

        return Inertia::render('invitations/Invalid', [
            'reason' => $problem->value,
            'title' => $problem->label(),
            'description' => $problem->description(),
        ]);
    }
}
