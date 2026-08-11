<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SessionDestroyRequest;
use App\Models\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's view of signed-in browsers, and the ability to end them.
 *
 * **Nothing here ever emits a session identifier.** A `sessions.id` *is* the value in the browser's
 * session cookie, so a copy of it in an Inertia prop, a data attribute, a URL or a log line is a
 * working impersonation credential for as long as that session lives. Every session on this screen
 * is addressed by `Session::digest()` and resolved back with `Session::findByDigest()`, which
 * compares in PHP with `hash_equals` because SQLite has no `sha2()` to compare with in SQL. See
 * `.ai/rules/sessions.md`.
 *
 * Ending the administrator's *own* session is a 403 rather than a logout. This screen exists to
 * remove other people's access; an administrator who wants to leave uses the ordinary log-out, and
 * `destroyOthers()` covers the case where they want every other browser gone but their own kept.
 */
class SessionController extends Controller
{
    /**
     * List every signed-in browser, most recently seen first.
     *
     * Guest session rows are filtered out by `Session::authenticated()`: the framework writes a row
     * for a signed-out visitor too, and "somebody loaded the login page" is not a session an
     * administrator has any use for.
     */
    public function index(Request $request): Response
    {
        $currentSessionId = $request->session()->getId();

        $sessions = Session::query()
            ->authenticated()
            ->with('user')
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (Session $session): array => $this->present($session, $currentSessionId))
            ->values()
            ->all();

        return Inertia::render('admin/sessions/Index', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * End one signed-in browser.
     *
     * A digest that matches no row is a 404 rather than a silent success: the row may have been
     * garbage-collected or ended from another tab since the screen was rendered, and reporting that
     * as "signed out" would tell the administrator something that did not happen.
     */
    public function destroy(SessionDestroyRequest $request): RedirectResponse
    {
        $session = Session::findByDigest($request->string('digest')->toString());

        abort_if($session === null, 404);
        abort_if($session->isCurrent($request->session()->getId()), 403);

        $signedOutName = $session->user?->name;

        $session->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $signedOutName === null
                ? __('That session was signed out.')
                : __(':name was signed out of that browser.', ['name' => $signedOutName]),
        ]);

        return to_route('admin.sessions.index');
    }

    /**
     * End every session except the one making this request.
     *
     * "Other" means every other browser in the installation, not just this administrator's own —
     * this is the whole-installation screen, and the bulk action it needs is the one that clears
     * everybody out while keeping the administrator signed in to see the result. Guest rows are
     * included: a global sign-out that left them behind would not be one.
     *
     * The current session is excluded in PHP rather than with a `whereNot`, so the comparison stays
     * a `hash_equals` on the identifier and there is one rule about how sessions are compared.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $currentSessionId = $request->session()->getId();

        $others = Session::query()
            ->get()
            ->reject(fn (Session $session): bool => $session->isCurrent($currentSessionId));

        Session::query()->whereIn('id', $others->modelKeys())->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0} There were no other sessions to sign out.|{1} One other session was signed out.|[2,*] :count other sessions were signed out.',
                $others->count(),
            ),
        ]);

        return to_route('admin.sessions.index');
    }

    /**
     * Shape one session for the administration table.
     *
     * There is deliberately **no** `id` key, and adding one is the mistake this whole class is
     * arranged to prevent — `tests/Feature/Admin/SessionIdentifierTest.php` walks the rendered props
     * and fails if an identifier appears anywhere in them. `digest` is what the sign-out form posts
     * back.
     *
     * @return array{
     *     digest: string,
     *     user_name: string|null,
     *     user_email: string|null,
     *     ip_address: string|null,
     *     browser: string,
     *     platform: string,
     *     last_active_at: string,
     *     last_active_at_diff: string,
     *     is_current: bool,
     * }
     */
    private function present(Session $session, string $currentSessionId): array
    {
        return [
            'digest' => $session->digest(),
            'user_name' => $session->user?->name,
            'user_email' => $session->user?->email,
            'ip_address' => $session->ip_address,
            'browser' => $session->browser(),
            'platform' => $session->platform(),
            'last_active_at' => $session->lastActiveAt()->toDayDateTimeString(),
            'last_active_at_diff' => $session->lastActiveAt()->diffForHumans(),
            'is_current' => $session->isCurrent($currentSessionId),
        ];
    }
}
