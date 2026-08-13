<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRoleUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's view of accounts: who exists, how they sign in, and the two things that can be
 * done about it.
 *
 * Both writes refuse to act on the administrator making the request. That is not politeness about
 * self-service — it is the only thing standing between an installation and having no administrator
 * at all, because `app:create-admin` needs a shell on the server to recover from that state.
 */
class UserController extends Controller
{
    /**
     * List every account a person signs in to, with the facts an administrator needs to judge it.
     *
     * Agent accounts are excluded and listed on `admin/agents` instead. Every column here is about
     * how a human reaches an account — verification, two-factor, live sessions — and every one of
     * them reads as an alarming blank for an account that authenticates by bearer token. The two
     * writes on this screen refuse an agent outright, so listing them would only be offering controls
     * that 403.
     *
     * The session count comes from a `withCount`, so a screen listing a hundred accounts still
     * issues one query rather than a hundred.
     */
    public function index(Request $request): Response
    {
        $currentUser = $this->authenticatedUser($request);

        $users = User::query()
            ->where('is_agent', false)
            ->withCount('sessions')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => $this->present($user, $currentUser))
            ->values()
            ->all();

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'roles' => array_map(
                fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
        ]);
    }

    /**
     * Change the application role an account holds.
     *
     * This is the only screen in the application allowed to write `users.role`, and it assigns the
     * column explicitly. `role` is not in `User`'s `#[Fillable]` list and must not be added to it —
     * everything else that writes an account does so from request input, so a fillable `role` would
     * let any of those posts promote itself (see `.ai/rules/roles.md`).
     */
    public function updateRole(UserRoleUpdateRequest $request, User $user): RedirectResponse
    {
        $this->abortWhenTargetingSelf($request, $user);

        /*
         * An agent account is never promotable. It holds a bearer token that a sandbox somewhere else
         * is authenticating with, so promoting it would turn a machine credential into an
         * administrator's credential — and unlike a person's account, there is nobody to notice.
         * `EnsureUserIsAdmin` would still refuse the *session*, because agents have none, but the
         * account would be an administrator for anything that reads the column.
         */
        abort_if($user->isAgent(), 403);

        /*
         * `enum()` is nullable and the rules make it `required`, so the fallback is unreachable — it
         * is `Member` rather than anything else so that if the rules are ever loosened, the
         * unreachable branch grants the *lesser* role instead of guessing.
         */
        $user->role = $request->enum('role', UserRole::class) ?? UserRole::Member;
        $user->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name is now :role.', [
                'name' => $user->name,
                'role' => $user->role->label(),
            ]),
        ]);

        return to_route('admin.users.index');
    }

    /**
     * Delete an account, and with it every browser signed in as that account.
     *
     * The session rows have to go **explicitly**: `sessions.user_id` deliberately carries no foreign
     * key, so nothing cascades, and a left-behind row is a browser that still holds a live session
     * cookie for an account that no longer exists. Passkeys are the deliberate contrast — that table
     * has `cascadeOnDelete`, so deleting them here as well would be duplicating a guarantee the
     * schema already makes.
     *
     * The two writes share a transaction so a failure cannot leave an account deleted with its
     * sessions standing, or the reverse.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->abortWhenTargetingSelf($request, $user);

        DB::transaction(function () use ($user): void {
            $user->sessions()->delete();
            $user->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name\'s account was deleted.', ['name' => $user->name]),
        ]);

        return to_route('admin.users.index');
    }

    /**
     * Refuse an action the administrator has aimed at their own account.
     *
     * `authenticatedUser()` rather than `$request->user()`: comparing against a null would make
     * `is()` return false and let the action through, so the guard would fail *open* on exactly the
     * request it exists for.
     */
    private function abortWhenTargetingSelf(Request $request, User $user): void
    {
        abort_if($user->is($this->authenticatedUser($request)), 403);
    }

    /**
     * Shape one account for the administration table.
     *
     * `is_self` is presentation only — it lets the screen leave out controls that would 403.
     * `abortWhenTargetingSelf()` is the boundary; do not turn a hidden button into the check.
     * `can_impersonate` is the same kind of flag for the same kind of reason: the rule it mirrors
     * lives in `App\Http\Controllers\ImpersonationController::store()`, which refuses the requester
     * and any administrator whether or not this screen offered a button.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     role: string,
     *     role_label: string,
     *     email_verified: bool,
     *     two_factor_enabled: bool,
     *     sessions_count: int,
     *     created_at: string,
     *     created_at_diff: string|null,
     *     is_self: bool,
     *     can_impersonate: bool,
     * }
     */
    private function present(User $user, User $currentUser): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'email_verified' => $user->hasVerifiedEmail(),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'sessions_count' => $user->sessions_count ?? 0,
            'created_at' => $user->created_at?->toDayDateTimeString() ?? '',
            'created_at_diff' => $user->created_at?->diffForHumans(),
            'is_self' => $user->is($currentUser),
            'can_impersonate' => ! $user->is($currentUser) && ! $user->isAdmin(),
        ];
    }
}
