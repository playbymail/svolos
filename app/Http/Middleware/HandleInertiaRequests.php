<?php

namespace App\Http\Middleware;

use App\Actions\Impersonation\ImpersonationSession;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'impersonator' => $this->presentImpersonator($request),
                'runsAGame' => $this->runsAGame($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Whether this session runs a game anywhere, so the sidebar can offer the kit template library.
     *
     * The same question `App\Http\Middleware\EnsureUserRunsAGame` asks, through the same scope, and
     * that is the whole point: the library is gated on holding an active gamemaster seat at *any*
     * game, so a nav item computed any other way is either a link that 403s or — the worse half — a
     * screen a gamemaster has no way to reach. It was shipped with no link at all once.
     *
     * This is a nav decision and **never** an authorisation one. Hiding the link is only about not
     * offering a 403; the gate on the routes is the boundary, exactly as `auth.user.role` hides the
     * administration item while the `admin` middleware refuses the area.
     *
     * It costs one `exists()` per Inertia response for a signed-in account and none at all for a
     * guest, who short-circuits on the null user. `Inertia::optional()` is the wrong tool despite
     * the shape: the sidebar renders on every page, so a prop that only arrives on a partial reload
     * would be absent precisely when it is read.
     */
    private function runsAGame(Request $request): bool
    {
        return $request->user()?->gameSeats()->activeGamemaster()->exists() === true;
    }

    /**
     * Shape the administrator behind an impersonated session for the banner, or null for everyone else.
     *
     * The question asked here is `isActive()`, **not** whether an administrator was found. The banner
     * is the only way out of an impersonated session, so a session that is impersonating always gets
     * one: if the administrator behind it was deleted or demoted mid-impersonation there is nobody to
     * name, and the prop carries nulls for the banner to render as "an administrator" rather than
     * disappearing and stranding the session with no exit. Returning null here for a session that is
     * still impersonating would hide the control that ends it.
     *
     * Built by hand rather than by sharing the model: `auth.user` is the account being impersonated
     * and is *meant* to be complete, but this is a second account appearing in the props of a
     * session that does not belong to it, so it carries only what the banner says out loud — the
     * name to identify who is really driving, and the email to disambiguate two people with the
     * same one.
     *
     * @return array{name: string|null, email: string|null}|null
     */
    private function presentImpersonator(Request $request): ?array
    {
        if (! ImpersonationSession::isActive($request)) {
            return null;
        }

        $impersonator = ImpersonationSession::impersonator($request);

        return [
            'name' => $impersonator?->name,
            'email' => $impersonator?->email,
        ];
    }
}
