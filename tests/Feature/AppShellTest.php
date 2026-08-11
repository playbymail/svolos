<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the shared inertia props expose the app name and a null user for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('name', config('app.name'))
            ->where('auth.user', null)
            ->where('sidebarOpen', true)
        );
});

test('the shared inertia props expose the signed in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('name', config('app.name'))
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
        );
});

test('the shared user prop never leaks the password hash or remember token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
        );
});

test('the sidebar starts open when the browser has sent no sidebar_state cookie', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sidebarOpen', true));
});

test('the sidebar_state cookie decides the sidebarOpen prop', function (string $cookie, bool $expected) {
    $this->withUnencryptedCookie('sidebar_state', $cookie)
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sidebarOpen', $expected));
})->with([
    'collapsed' => ['false', false],
    'expanded' => ['true', true],
]);

test('a controller flashing a toast makes it available where the frontend reads it', function () {
    $user = User::factory()->create();

    $expected = ['type' => 'success', 'message' => 'Profile updated.'];

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Player',
        'email' => $user->email,
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertInertiaFlash('toast', $expected);

    /*
     * Inertia::flash() only reaches the browser on the response after the redirect, in the page
     * object's `flash` bag rather than its props — that is the bag resources/js/lib/flash-toast.ts
     * subscribes to, so following the redirect is what proves the payload actually arrives.
     */
    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->hasFlash('toast', $expected)
        );
});

test('a page with nothing flashed carries no toast', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('toast'));
});
