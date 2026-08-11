<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The administrator boundary
|--------------------------------------------------------------------------
|
| /admin is gated by ['auth', 'verified', 'admin'] in that order, and the order is the
| behaviour being asserted: a guest must be sent to the login page by `auth` rather than
| shown a 403 that confirms the route exists, while a signed-in member — who has nothing
| to gain from a login page — gets the 403.
|
| The middleware sweep at the bottom is what keeps this file honest as later tasks add
| screens to the area: a new route named admin.* that forgets one of the three fails here
| without anybody remembering to add a case.
|
*/

test('a guest is redirected to login from the admin area', function () {
    $response = $this->get(route('admin.index'));

    $response->assertRedirect(route('login'));
    $response->assertStatus(302);
});

test('a member is forbidden from the admin area', function () {
    $member = User::factory()->create();

    $response = $this->actingAs($member)->get(route('admin.index'));

    $response->assertForbidden();
});

test('an unverified administrator is sent to email verification', function () {
    $admin = User::factory()->admin()->unverified()->create();

    $response = $this->actingAs($admin)->get(route('admin.index'));

    $response->assertRedirect(route('verification.notice'));
});

test('an administrator can reach the admin area', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.index'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/Index')
        ->where('auth.user.role', 'admin'),
    );
});

test('a member is forbidden by the admin middleware even without the route group', function () {
    Route::middleware(['auth', 'admin'])->get('/testing/admin-only', fn () => 'reached');

    $member = User::factory()->create();

    $this->actingAs($member)->get('/testing/admin-only')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/testing/admin-only')->assertOk();
});

test('the admin middleware fails closed for a request with no authenticated user', function () {
    Route::middleware('admin')->get('/testing/admin-only-unguarded', fn () => 'reached');

    $this->get('/testing/admin-only-unguarded')->assertForbidden();
});

test('every route in the admin area is behind auth, verified and admin', function () {
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.'));

    expect($adminRoutes)->not->toBeEmpty();

    $adminRoutes->each(function (RoutingRoute $route): void {
        expect($route->gatherMiddleware())
            ->toContain('auth')
            ->toContain('verified')
            ->toContain('admin');
    });
});

test('the admin alias resolves to the admin middleware', function () {
    expect(app(HttpKernel::class)->getMiddlewareAliases())
        ->toHaveKey('admin', EnsureUserIsAdmin::class);
});
