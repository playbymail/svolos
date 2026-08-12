<?php

namespace App\Http\Controllers\Dev;

use App\Actions\Impersonation\ImpersonationSession;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a local development session in as an existing account, without a password.
 *
 * ## What this is for
 *
 * Driving the application in a real browser — by hand, or by an agent that is not permitted to type
 * credentials into a login form. `GET /__dev/log-me-in/{email}?returnTo=/some/path` puts the session
 * on that account and lands on that path.
 *
 * ## It is an authentication bypass, so read this before changing it
 *
 * There are **two independent gates**, and both must stay:
 *
 * 1. `routes/dev.php` is only required at all when the application is in the `local` environment, so
 *    outside local the route does not exist and the URL is an ordinary 404;
 * 2. this controller checks the environment **again** on every request. That is not redundant with
 *    the first: `php artisan route:cache` run on a local machine bakes the route into a file, and a
 *    deploy that shipped that file would otherwise carry a working bypass into production.
 *
 * Neither gate is a config flag on purpose. A flag is a thing somebody can turn on in the wrong
 * place; `APP_ENV=local` is already the switch that says "this installation is a workstation".
 *
 * It signs accounts **in**; it never creates one. There is no account creation anywhere outside
 * invitation acceptance (see `.ai/rules/auth.md`), and this does not become the exception.
 */
class AgentLoginController extends Controller
{
    /**
     * Sign in as the account with this address.
     */
    public function __invoke(Request $request, string $email): RedirectResponse
    {
        /* The second gate. See the class doc block for why this is not the same check twice. */
        abort_unless(app()->environment('local'), 404);

        $user = User::query()->firstWhere('email', $email);

        abort_if($user === null, 404, $this->unknownAddressMessage($email));

        Auth::login($user);

        /* A fresh session id, exactly as a real login does — a dev shortcut is not a reason to leave
         * a session fixation lying around in something people will paste URLs into. */
        $request->session()->regenerate();

        /* Whoever was here before might have been impersonating somebody. This is a new session as a
         * new account, so the banner has nothing to say. */
        $request->session()->forget(ImpersonationSession::SESSION_KEY);

        return redirect()->to($this->destination($request->query('returnTo')));
    }

    /**
     * Work out where to land, refusing to be bounced off the application.
     *
     * Only a path on this application is accepted. `//evil.example` and `/\evil.example` are how a
     * protocol-relative URL disguises itself as a path, and both are rejected here rather than trusted
     * because the endpoint is local-only — an open redirect is not worth having in any environment,
     * and this one is going to end up in shell history and agent transcripts.
     */
    private function destination(mixed $returnTo): string
    {
        if (! is_string($returnTo) || $returnTo === '') {
            return route('dashboard');
        }

        $isRelativePath = str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! str_starts_with($returnTo, '/\\');

        return $isRelativePath ? url($returnTo) : route('dashboard');
    }

    /**
     * Explain a miss with the addresses that would have worked.
     *
     * Local only, so listing them discloses nothing that is not already in the seeder — and it saves
     * whoever is driving the browser a trip through tinker to find out what the seeded accounts are.
     */
    private function unknownAddressMessage(string $email): string
    {
        $known = User::query()->orderBy('id')->limit(5)->pluck('email')->implode(', ');

        return $known === ''
            ? "No account with the address {$email}, and this installation has no accounts at all. Run `php artisan db:seed`."
            : "No account with the address {$email}. Accounts that exist include: {$known}";
    }
}
