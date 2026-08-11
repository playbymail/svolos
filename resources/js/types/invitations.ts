import type { UserRole, UserRoleOption } from '@/types/auth';

/**
 * Mirrors the `App\Enums\InvitationStatus` backed enum, which serialises to its value.
 *
 * Derived on the server from the invitation's timestamps — there is no status column.
 */
export type InvitationStatus = 'pending' | 'accepted' | 'expired';

/**
 * Mirrors the `App\Enums\InvitationLinkProblem` backed enum.
 *
 * Why an invitation link cannot be used. The three cases are kept apart because they have three
 * different remedies: check the link, ask for a new invitation, or simply log in.
 */
export type InvitationLinkProblem = 'unknown' | 'expired' | 'accepted';

/**
 * One row of the administration table, as shaped by
 * `App\Http\Controllers\Admin\InvitationController::present()`.
 *
 * There is deliberately no `token`: the model hides it from serialisation, and the value would be a
 * useless hash even if it were sent.
 */
export type Invitation = {
    id: number;
    email: string;
    role: UserRole;
    role_label: string;
    status: InvitationStatus;
    status_label: string;
    invited_by: string | null;
    expires_at: string;
    expires_at_diff: string;
    accepted_at_diff: string | null;
    created_at_diff: string | null;
};

/**
 * A selectable role in the invitation form, labelled by `App\Enums\UserRole::label()`.
 */
export type InvitationRoleOption = UserRoleOption;
