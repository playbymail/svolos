<?php

use App\Actions\Impersonation\ImpersonationSession;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Impersonation
|--------------------------------------------------------------------------
|
| An administrator can sign in as a member to see what that member sees. The session really is
| the member's — their data, their permissions — with one extra session key naming the
| administrator who started it, which is what `impersonation.stop` reads to put them back.
|
| That key is a privilege record, not a credential: whoever holds it gets an administrator's
| account back on the way out. So the two questions this file exists to answer are who may write
| it, and what a session carrying it is still allowed to do.
|
| The answers are asserted from both ends. Starting is refused for a guest, a member, an
| unverified administrator, an administrator aiming at themselves, and — the one that matters —
| an administrator aiming at another administrator: impersonation is meant to reach downward, to
| accounts an administrator can already delete, not sideways between peers.
|
| A session that is already impersonating is refused the whole admin area, including the case
| where the borrowed account is itself an administrator. That case cannot be produced through the
| browser today (only members can be impersonated), which is exactly why it is asserted here: an
| account can be promoted while somebody is inside it, and the guarantee has to belong to the
| middleware rather than to a rule one controller away.
|
| Because `phpunit.xml` sets `SESSION_DRIVER=array` and the harness sends no session cookie back,
| session data does not survive from one request to the next — see `.ai/rules/sessions.md`. An
| already-impersonating request is therefore built with `withSession()` rather than by following
| the start request, which is also the more honest test: it asserts what the *guards* do with the
| key, not what one particular path happens to leave behind.
|
*/

/**
 * A request made from a session that is already impersonating `$impersonator`'s way into `$borrowed`.
 */
function actingAsImpersonated(User $borrowed, User $impersonator): TestCase
{
    return test()
        ->actingAs($borrowed)
        ->withSession([ImpersonationSession::SESSION_KEY => $impersonator->getKey()]);
}

test('a guest is redirected to login from both impersonation routes', function () {
    $target = User::factory()->create();

    $this->post(route('admin.users.impersonate', ['user' => $target]))->assertRedirect(route('login'));
    $this->delete(route('impersonation.stop'))->assertRedirect(route('login'));
});

test('a member cannot start an impersonation', function () {
    $member = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($member)
        ->post(route('admin.users.impersonate', ['user' => $target]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($member);
});

test('an unverified administrator is sent to email verification before starting', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonate', ['user' => $target]))
        ->assertRedirect(route('verification.notice'));

    $this->assertAuthenticatedAs($admin);
});

test('an administrator can impersonate a member', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)
        ->post(route('admin.users.impersonate', ['user' => $member]));

    /*
     * The dashboard rather than back to the accounts screen: the session is a member's from here
     * on, and `/admin` would refuse it the moment it landed.
     */
    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'You are now signed in as Grace Hopper.',
    ]);
    $response->assertSessionHas(ImpersonationSession::SESSION_KEY, $admin->getKey());

    $this->assertAuthenticatedAs($member);

    /* Impersonation borrows an account; it does not alter either one. */
    expect($member->fresh()?->role)->toBe(UserRole::Member)
        ->and($admin->fresh()?->role)->toBe(UserRole::Admin);
});

test('an administrator cannot impersonate themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonate', ['user' => $admin]))
        ->assertForbidden()
        ->assertSessionMissing(ImpersonationSession::SESSION_KEY);

    $this->assertAuthenticatedAs($admin);
});

test('an administrator cannot impersonate another administrator', function () {
    /*
     * The refusal this feature is shaped around. Reaching a member hands an administrator nothing
     * they did not already have — they can delete that account outright. Reaching a *peer* would
     * let any administrator act as any other with nothing in `users` recording that they did.
     */
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonate', ['user' => $other]))
        ->assertForbidden()
        ->assertSessionMissing(ImpersonationSession::SESSION_KEY);

    $this->assertAuthenticatedAs($admin);
});

test('an impersonated session is shut out of the whole admin area', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    foreach ([
        'admin.index',
        'admin.users.index',
        'admin.invitations.index',
        'admin.sessions.index',
        'admin.games.index',
    ] as $name) {
        actingAsImpersonated($member, $admin)->get(route($name))->assertForbidden("reached {$name}");
    }
});

test('an impersonated session is refused even when the account it borrowed is an administrator', function () {
    /*
     * Not reachable through the browser — `store()` refuses an administrator as a target — and that
     * is the point. An account can be promoted by a second administrator while somebody is inside
     * it, and without this check that promotion would silently upgrade a borrowed session into a
     * full administrator one. The guarantee belongs to `EnsureUserIsAdmin`, not to the controller.
     */
    $admin = User::factory()->admin()->create();
    $promoted = User::factory()->admin()->create();

    /*
     * The positive control comes first: that same account reaches the screen on an ordinary
     * session, so the 403 below is the impersonation key and not something else about the account.
     * `withSession()` writes into the application's session store, which outlives a single request
     * inside one test, so the control cannot be run after the impersonated request without
     * flushing it first.
     */
    $this->actingAs($promoted)->get(route('admin.index'))->assertOk();

    actingAsImpersonated($promoted, $admin)->get(route('admin.index'))->assertForbidden();
});

test('an impersonation cannot be nested inside another', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $another = User::factory()->create();

    actingAsImpersonated($member, $admin)
        ->post(route('admin.users.impersonate', ['user' => $another]))
        ->assertForbidden();
});

test('an administrator can stop impersonating and comes back as themselves', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);
    $member = User::factory()->create();

    $response = actingAsImpersonated($member, $admin)->delete(route('impersonation.stop'));

    $response->assertRedirect(route('admin.users.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'You are signed in as Ada Lovelace again.',
    ]);
    $response->assertSessionMissing(ImpersonationSession::SESSION_KEY);

    $this->assertAuthenticatedAs($admin);
});

test('stopping works while impersonating an account that has not verified its address', function () {
    /*
     * Why `impersonation.stop` is on `auth` alone. Behind `verified`, impersonating an unverified
     * account would bounce the administrator to the verification notice with no way back to their
     * own account short of clearing their cookies.
     */
    $admin = User::factory()->admin()->create();
    $unverified = User::factory()->unverified()->create();

    actingAsImpersonated($unverified, $admin)
        ->delete(route('impersonation.stop'))
        ->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($admin);
});

test('stopping without an impersonation to stop is forbidden', function () {
    $member = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($member)->delete(route('impersonation.stop'))->assertForbidden();
    $this->assertAuthenticatedAs($member);

    $this->actingAs($admin)->delete(route('impersonation.stop'))->assertForbidden();
    $this->assertAuthenticatedAs($admin);
});

test('stopping signs the browser out when the administrator behind it no longer exists', function () {
    /*
     * Reachable: a second administrator can delete the first one's account mid-impersonation,
     * because by then the session row belongs to the *member* and so is not among the rows that
     * deletion removes (see `Admin\UserController::destroy()`). There is nobody to go back to, and
     * leaving the browser signed in as the member would turn a deleted administrator into an
     * anonymous foothold in somebody else's account.
     */
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $request = actingAsImpersonated($member, $admin);

    $admin->delete();

    $response = $request->delete(route('impersonation.stop'));

    $response->assertRedirect(route('login'));
    $response->assertSessionMissing(ImpersonationSession::SESSION_KEY);

    $this->assertGuest();
});

test('the stop route is deliberately outside the admin area', function () {
    /*
     * The session calling it is a member's, so `admin` would refuse the one request that ends the
     * impersonation and `verified` would strand an administrator inside an unverified account.
     * `AdminAccessTest` requires all three on every `admin.*` route, so the name matters too.
     */
    $route = Route::getRoutes()->getByName('impersonation.stop');

    expect($route)->toBeInstanceOf(RoutingRoute::class);
    expect((string) $route?->getName())->not->toStartWith('admin.');

    $middleware = $route?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('auth');
    expect($middleware)->not->toContain('admin');
    expect($middleware)->not->toContain('verified');
});

test('the shared props name the administrator behind an impersonated session', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    actingAsImpersonated($member, $admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.user.id', $member->id)
            ->where('auth.user.name', 'Grace Hopper')
            /*
             * No `->etc()`: every key of the impersonator prop is named here, so the whole second
             * account cannot start arriving in the props of a session that is not its own.
             */
            ->has('auth.impersonator', fn (Assert $impersonator) => $impersonator
                ->where('name', 'Ada Lovelace')
                ->where('email', 'ada@example.com'),
            ),
        );
});

test('an ordinary session carries a null impersonator', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.impersonator', null));

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.impersonator', null));
});

test('the accounts screen offers impersonation for members other than the requester', function () {
    /*
     * Presentation only, mirroring what `ImpersonationController::store()` refuses. Ordered by name.
     */
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);
    $peer = User::factory()->admin()->create(['name' => 'Bob Admin']);
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.0.name', 'Ada Lovelace')
            ->where('users.0.can_impersonate', false)
            ->where('users.1.name', 'Bob Admin')
            ->where('users.1.can_impersonate', false)
            ->where('users.2.name', 'Grace Hopper')
            ->where('users.2.can_impersonate', true),
        );

    expect($peer->isAdmin())->toBeTrue()
        ->and($member->isAdmin())->toBeFalse();
});
