<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| An agent account is not reachable by a person
|--------------------------------------------------------------------------
|
| An agent holds a bearer token that a sandbox somewhere else authenticates with. Whatever
| that token can do, anybody who gets *into* the account can do — so the four doors a person
| could otherwise walk through are each closed, and each is asserted here.
|
| The password is not one of the four. Every agent has 64 random characters nobody has ever
| seen, so in practice no sign-in would succeed anyway — which is exactly why these tests give
| an agent a **known** password before trying. Otherwise they would pass whether or not the
| refusals existed, and prove nothing about the rule. That is the same trap `.ai/rules/auth.md`
| describes for skipped tests: assert absence positively, or do not claim it.
|
*/

test('an agent cannot sign in, even with the right password', function () {
    $agent = User::factory()->agent()->create(['password' => 'a-known-password']);

    $response = $this->post(route('login'), [
        'email' => $agent->email,
        'password' => 'a-known-password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors();
});

test('a person with the same password still signs in', function () {
    /*
     * The control for the test above. Without it, a mistake that broke sign-in for *everybody*
     * would read as the agent refusal working.
     */
    $person = User::factory()->create(['password' => 'a-known-password']);

    $this->post(route('login'), [
        'email' => $person->email,
        'password' => 'a-known-password',
    ]);

    $this->assertAuthenticatedAs($person);
});

test('an agent is sent no password reset link', function () {
    Notification::fake();

    $agent = User::factory()->agent()->create();

    $this->post(route('password.email'), ['email' => $agent->email]);

    Notification::assertNothingSent();
});

test('a person is still sent a password reset link', function () {
    Notification::fake();

    $person = User::factory()->create();

    $this->post(route('password.email'), ['email' => $person->email]);

    Notification::assertSentTo($person, ResetPassword::class);
});

test('an administrator cannot impersonate an agent', function () {
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonate', $agent))
        ->assertForbidden();

    $this->assertAuthenticatedAs($admin);
});

test('an administrator cannot promote an agent', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.users.role.update', $agent), ['role' => UserRole::Admin->value])
        ->assertForbidden();

    expect($agent->fresh()?->isAdmin())->toBeFalse();
});

test('an administrator cannot demote an agent either', function () {
    /*
     * The refusal is about the *account*, not about the direction of the change. A write that only
     * refused promotion would still let this screen be the place agent accounts get edited, and the
     * next role added to the enum would have to remember the rule again.
     */
    $agent = User::factory()->agent()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.users.role.update', $agent), ['role' => UserRole::Member->value])
        ->assertForbidden();
});

test('agent-ness is not something a role change can confer', function () {
    /*
     * The inverse of the rule: `is_agent` and `role` are separate columns answering separate
     * questions, so the accounts screen cannot turn a person into an agent by any route it offers.
     */
    $person = User::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.users.role.update', $person), [
            'role' => UserRole::Admin->value,
            'is_agent' => true,
        ]);

    expect($person->fresh()?->isAgent())->toBeFalse();
});
