<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ConfirmPassword'),
    );
});

test('password confirmation requires authentication', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('the password can be confirmed with the correct password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('password.confirmation'))
        ->assertOk()
        ->assertExactJson(['confirmed' => true]);
});

test('the password cannot be confirmed with the wrong password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->get(route('password.confirmation'))
        ->assertExactJson(['confirmed' => false]);
});

test('confirming the password unlocks a route behind the password confirmation middleware', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->get(route('security.edit'))->assertOk();
});
