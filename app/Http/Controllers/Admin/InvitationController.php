<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Invitations\IssueInvitation;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvitationStoreRequest;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's view of invitations: who has been invited, and the three things that can be
 * done about it.
 *
 * Every write goes through `App\Actions\Invitations\IssueInvitation` rather than touching the model,
 * so there is exactly one place that mints a token and sends mail. This controller decides *whether*
 * to issue one, never *how*.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly IssueInvitation $issueInvitation) {}

    /**
     * List every invitation, whatever state it is in.
     *
     * Accepted and expired rows are deliberately not filtered out: the list doubles as the record of
     * who was let in and who never came, and hiding an expired invitation is how the same person
     * gets invited three times.
     */
    public function index(): Response
    {
        $invitations = Invitation::query()
            ->with('invitedBy')
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation): array => $this->present($invitation))
            ->values()
            ->all();

        return Inertia::render('admin/invitations/Index', [
            'invitations' => $invitations,
            'roles' => array_map(
                fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
            'expiresAfterDays' => Invitation::EXPIRES_AFTER_DAYS,
        ]);
    }

    /**
     * Invite an email address, choosing the role the resulting account will hold.
     *
     * Re-inviting an address that already has an invitation is allowed and is not a mistake: the
     * action upserts the row, so it reissues the one invitation rather than leaving two live links
     * behind. `InvitationStoreRequest` is what stops an address that already has an *account* from
     * being invited.
     */
    public function store(InvitationStoreRequest $request): RedirectResponse
    {
        /*
         * `enum()` is nullable and the rules make it `required`, so the fallback is unreachable —
         * it is `Member` rather than anything else so that if the rules are ever loosened, the
         * unreachable branch grants the *lesser* role instead of guessing.
         */
        $invitation = $this->issueInvitation->handle(
            $request->string('email')->toString(),
            $request->enum('role', UserRole::class) ?? UserRole::Member,
            $this->authenticatedUser($request),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invitation sent to :email.', ['email' => $invitation->email]),
        ]);

        return to_route('admin.invitations.index');
    }

    /**
     * Send a fresh link for an invitation that has not been accepted.
     *
     * **This issues a new token and kills the previously emailed link**, because the stored token is
     * a hash and the plain text cannot be recovered to send again. Resending an already accepted
     * invitation is a 403 rather than a no-op: there is no link left to send, the account already
     * exists, and quietly succeeding would suggest otherwise.
     *
     * The row's `invited_by_id` becomes the administrator who resent it. The column records who
     * issued the link that is currently live, and after a resend that is this administrator.
     */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->isAccepted(), 403);

        $this->issueInvitation->handle(
            $invitation->email,
            $invitation->role,
            $this->authenticatedUser($request),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('A new invitation link was sent to :email.', ['email' => $invitation->email]),
        ]);

        return to_route('admin.invitations.index');
    }

    /**
     * Withdraw an invitation, taking its link with it.
     *
     * Deleting the row is what revokes the link: acceptance looks the token hash up in this table, so
     * a deleted invitation is indistinguishable from a token that never existed.
     *
     * An accepted invitation can be deleted too, and doing so removes only the record — the account
     * it created is untouched. That is why this is not the way to remove somebody's access.
     */
    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The invitation for :email was revoked.', ['email' => $invitation->email]),
        ]);

        return to_route('admin.invitations.index');
    }

    /**
     * Shape one invitation for the administration table.
     *
     * Dates are formatted here rather than on the client for the same reason the passkey list does
     * it: the server already knows the application's locale and timezone.
     *
     * @return array{
     *     id: int,
     *     email: string,
     *     role: string,
     *     role_label: string,
     *     status: string,
     *     status_label: string,
     *     invited_by: string|null,
     *     expires_at: string,
     *     expires_at_diff: string,
     *     accepted_at_diff: string|null,
     *     created_at_diff: string|null,
     * }
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'status' => $invitation->status()->value,
            'status_label' => $invitation->status()->label(),
            'invited_by' => $invitation->invitedBy?->name,
            'expires_at' => $invitation->expires_at->toDayDateTimeString(),
            'expires_at_diff' => $invitation->expires_at->diffForHumans(),
            'accepted_at_diff' => $invitation->accepted_at?->diffForHumans(),
            'created_at_diff' => $invitation->created_at?->diffForHumans(),
        ];
    }
}
