<?php

use App\Actions\Impersonation\ImpersonationSession;
use App\Http\Controllers\Dev\AgentLoginController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The development sign-in endpoint
|--------------------------------------------------------------------------
|
| `/__dev/log-me-in/{email}` signs a browser in as an existing account with no password, so the
| application can be driven by hand or by an agent that may not type credentials into a form.
|
| **It is an authentication bypass, so the tests that matter most here are the ones asserting it does
| not exist.** There are two independent gates and each is tested on its own, because either one alone
| would look sufficient and neither is: the route is only registered in `local`, *and* the controller
| re-checks the environment, which is what protects a deploy carrying a route cache that was built on
| somebody's laptop.
|
| The suite runs with `APP_ENV=testing`, so the route is genuinely absent here. Tests of what the
| endpoint *does* register it themselves and flip the environment for the duration, which is also the
| only honest way to prove the controller's own check fires: it has to be reachable to refuse.
|
*/

/**
 * Register the endpoint the way `routes/dev.php` does, inside the `web` group so there is a session.
 */
function registerDevLoginRoute(): void
{
    Route::middleware('web')
        ->get('__dev/log-me-in/{email}', AgentLoginController::class)
        ->where('email', '[^/]+')
        ->name('dev.login');
}

test('the route does not exist outside local', function () {
    /*
     * The first gate, asserted in the environment the suite actually runs in. `routes/dev.php` is
     * never required here, so there is nothing to request.
     */
    expect(app()->environment('local'))->toBeFalse();
    expect(Route::has('dev.login'))->toBeFalse();

    $user = User::factory()->create();

    $this->get('/__dev/log-me-in/'.$user->email)->assertNotFound();
    $this->assertGuest();
});

test('the controller refuses even when the route is registered outside local', function () {
    /*
     * **The second gate, and the one worth having.** A route cache built on a workstation and shipped
     * would put this route into a production route table with no `if` in front of it. Registering it
     * by hand here is exactly that situation, and the controller has to refuse on its own.
     */
    registerDevLoginRoute();

    $user = User::factory()->create();

    $this->get('/__dev/log-me-in/'.$user->email)->assertNotFound();
    $this->assertGuest();
});

test('it signs in as the account and lands on the path it was given', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->get('/__dev/log-me-in/ada@example.com?returnTo=/settings/profile')
        ->assertRedirect('/settings/profile');

    $this->assertAuthenticatedAs($user);
});

test('it lands on the dashboard when no path is asked for', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $user = User::factory()->create();

    $this->get('/__dev/log-me-in/'.$user->email)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('it will not be bounced off the application', function (string $returnTo) {
    /*
     * An open redirect is not worth having in any environment, and this URL is going to end up in
     * shell history and agent transcripts. `//host` and `/\host` are how a protocol-relative URL
     * disguises itself as a path, which is why "starts with a slash" is not the whole check.
     */
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $user = User::factory()->create();

    $this->get('/__dev/log-me-in/'.$user->email.'?returnTo='.urlencode($returnTo))
        ->assertRedirect(route('dashboard'));

    /* Refusing the destination is not refusing the sign-in: the session is still on the account. */
    $this->assertAuthenticatedAs($user);
})->with([
    'absolute url' => 'https://evil.example/phish',
    'protocol relative' => '//evil.example/phish',
    'backslash protocol relative' => '/\\evil.example/phish',
    'scheme without a slash' => 'javascript:alert(1)',
    'bare path with no leading slash' => 'evil.example',
]);

test('an unknown address signs nobody in, and says which addresses would have worked', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->get('/__dev/log-me-in/nobody@example.com');

    $response->assertNotFound();
    $this->assertGuest();

    /* The message is the affordance: whoever guessed wrong should not need tinker to guess again. */
    expect($response->exception?->getMessage())->toContain('ada@example.com');
});

test('it swaps accounts cleanly, dropping an impersonation left over from the session before', function () {
    /*
     * The session may already belong to an administrator who was impersonating somebody. This is a
     * fresh sign-in as a different account, so the impersonation marker must not survive it — a
     * stale one would leave the banner offering to "return" to an administrator this session never
     * was.
     */
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($admin)
        ->withSession([ImpersonationSession::SESSION_KEY => $admin->id])
        ->get('/__dev/log-me-in/member@example.com')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($member);
    $this->assertFalse(session()->has(ImpersonationSession::SESSION_KEY));
});

test('an unverified account is signed in and left to the verification gate', function () {
    /*
     * Signing in is not verifying. An unverified account is exactly the state somebody may need to
     * drive the browser in, and `verified` is what decides where it can go — this endpoint has no
     * opinion.
     */
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $user = User::factory()->unverified()->create();

    $this->get('/__dev/log-me-in/'.$user->email)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('it creates nothing', function () {
    /*
     * Accounts come from invitations and from nowhere else (see `.ai/rules/auth.md`). A convenience
     * that quietly minted an account on a miss would be a second door into that rule.
     */
    $this->app->detectEnvironment(fn (): string => 'local');
    registerDevLoginRoute();

    $before = User::query()->count();

    $this->get('/__dev/log-me-in/nobody@example.com')->assertNotFound();

    expect(User::query()->count())->toBe($before);
});
