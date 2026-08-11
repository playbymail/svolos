/**
 * Mirrors the `App\Enums\UserRole` backed enum, which serialises to its value.
 *
 * This is the application role that gates `/admin`. It is unrelated to any role a user holds
 * inside a game.
 */
export type UserRole = 'admin' | 'member';

/**
 * A selectable role, labelled on the server by `App\Enums\UserRole::label()` so the frontend never
 * maps raw case values to human strings.
 */
export type UserRoleOption = {
    value: UserRole;
    label: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: UserRole;
    two_factor_enabled?: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * The administrator behind an impersonated session, as shaped by
 * `App\Http\Middleware\HandleInertiaRequests::presentImpersonator()`.
 *
 * Null on every ordinary session. When it is not null, `auth.user` is somebody *else* — the account
 * being impersonated — which is the one situation where those two props disagree about who is using
 * the application, and the only reason this prop exists.
 *
 * The fields are nullable but the object is not: an administrator can be deleted or demoted while
 * somebody is inside an account, and the banner is the only way out of that session, so it is still
 * shown with "an administrator" in place of a name. A non-null object means "this session is
 * impersonating"; the name and email only answer "by whom", and may not have an answer.
 */
export type Impersonator = {
    name: string | null;
    email: string | null;
};

export type Auth = {
    user: User;
    impersonator: Impersonator | null;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
