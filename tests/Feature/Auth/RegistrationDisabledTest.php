<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Registration is deliberately disabled
|--------------------------------------------------------------------------
|
| Accounts are created only by accepting an invitation, so Features::registration()
| is absent from config/fortify.php and Fortify registers no /register routes. These
| assertions are positive on purpose: a test that skipped itself when the feature was
| missing would silently stop guarding anything.
|
| The literal path '/register' is used rather than route('register') because the named
| route no longer exists — route() would throw instead of asserting.
|
*/

test('the registration feature is not enabled', function () {
    expect(config('fortify.features'))->not->toContain(Features::registration());
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('the registration routes are not registered', function () {
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('register.store'))->toBeFalse();
});

test('the registration screen returns 404', function () {
    $this->get('/register')->assertNotFound();
});

test('the registration endpoint returns 404 and creates no user', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('an authenticated user also gets a 404 from the registration screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/register')->assertNotFound();
});
