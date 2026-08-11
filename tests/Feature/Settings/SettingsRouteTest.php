<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The /settings surface: entry point and authorisation boundary
|--------------------------------------------------------------------------
|
| Behaviour of the individual screens lives in ProfileUpdateTest and SecurityTest. This file
| covers the two things that are properties of the route table rather than of any one screen:
| where /settings sends a signed-in user, and which middleware each route is actually behind.
|
| The split is deliberate. Every settings route requires `auth`; only the destructive and
| security-sensitive ones also require `verified`, so an invited user who has not clicked the
| verification link yet can still read their profile, correct the address the link was sent to,
| and choose a theme. The unverified *blocking* half is asserted in
| tests/Feature/Auth/EmailVerificationTest.php; what is asserted here is the complement — that
| the auth-only routes really are reachable without verification, which is the half a stray
| `verified` in the wrong group would break.
|
*/

test('the settings index redirects to the profile page', function () {
    /*
     * routes/settings.php has to spell the destination as a literal path, because route names
     * cannot be resolved while the route files are still loading. Asserting against the named
     * route is what ties the literal back to it: rename settings/profile without updating the
     * redirect and this fails.
     */
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertRedirect(route('profile.edit'));
});

test('a guest is sent to login from every settings route', function (string $method, string $url) {
    $this->call($method, $url)->assertRedirect(route('login'));

    $this->assertGuest();
})->with([
    'settings index' => ['GET', '/settings'],
    'profile page' => ['GET', fn () => route('profile.edit')],
    'profile update' => ['PATCH', fn () => route('profile.update')],
    'account deletion' => ['DELETE', fn () => route('profile.destroy')],
    'security page' => ['GET', fn () => route('security.edit')],
    'password update' => ['PUT', fn () => route('user-password.update')],
    'appearance page' => ['GET', fn () => route('appearance.edit')],
]);

test('an unverified user can still reach the profile page', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Profile'));
});

test('an unverified user can still correct their email address', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'corrected@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('corrected@example.com');
});

test('an unverified user can still change the theme', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Appearance'));
});
