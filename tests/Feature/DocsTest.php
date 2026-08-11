<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the docs route renders the docs inertia page for guests', function () {
    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Docs')
            ->where('name', config('app.name'))
            ->where('auth.user', null)
        );
});

test('the docs route renders for signed in users too', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Docs')
            ->where('auth.user.id', $user->id)
        );
});
