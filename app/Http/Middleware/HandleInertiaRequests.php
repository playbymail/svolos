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
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
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
