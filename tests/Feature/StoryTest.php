<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

test('the story route renders the story inertia page for guests', function () {
    $this->get(route('story'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Story')
            ->where('name', config('app.name'))
            ->where('auth.user', null)
        );
});

test('the story route renders for signed in users too', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('story'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Story')
            ->where('auth.user.id', $user->id)
        );
});

/*
 * The introduction is the one thing a visitor who has never been invited comes here to read, so the
 * route carries no `auth` and no `verified`. Asserting the middleware rather than only the 200 keeps
 * a later group edit from putting it behind the sign-in without anything failing.
 */
test('the story route is not behind any authentication middleware', function () {
    $middleware = collect(Route::getRoutes()->getByName('story')->gatherMiddleware());

    expect($middleware)->not->toContain('auth')
        ->and($middleware)->not->toContain('verified')
        ->and($middleware)->not->toContain('guest');
});
