import type { UserRole } from '@/types/auth';

/**
 * One row of the accounts table, as shaped by
 * `App\Http\Controllers\Admin\UserController::present()`.
 *
 * `is_self` marks the administrator's own row. It exists so the screen can leave out controls that
 * would be refused — the server is the boundary (`UserController::abortWhenTargetingSelf()` returns
 * 403 for a change of role or a delete aimed at the requester), and a hidden button is never the
 * check.
 *
 * `sessions_count` is how many rows the account has in the `sessions` table, which is what the
 * application treats as a signed-in browser.
 */
export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    role_label: string;
    email_verified: boolean;
    two_factor_enabled: boolean;
    sessions_count: number;
    created_at: string;
    created_at_diff: string | null;
    is_self: boolean;
};

/**
 * One row of the sessions table, as shaped by
 * `App\Http\Controllers\Admin\SessionController::present()`.
 *
 * **There is deliberately no `id`.** A `sessions.id` is the live value in that browser's session
 * cookie, so anything holding it can impersonate the browser. Sessions are addressed by `digest`
 * (sha256 of the identifier), which is what the sign-out form posts back. Do not add an `id` field
 * here, and do not put a digest into a URL — it belongs in the request body.
 */
export type AdminSession = {
    digest: string;
    user_name: string | null;
    user_email: string | null;
    ip_address: string | null;
    browser: string;
    platform: string;
    last_active_at: string;
    last_active_at_diff: string;
    is_current: boolean;
};
