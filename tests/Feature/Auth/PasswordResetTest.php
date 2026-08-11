<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ForgotPassword'),
    );
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/ResetPassword')
            ->where('token', $notification->token)
            ->has('passwordRules'),
        );

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('a valid token actually changes the stored password', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-completely-new-password',
            'password_confirmation' => 'a-completely-new-password',
        ])->assertSessionHasNoErrors();

        expect(Hash::check('a-completely-new-password', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('password cannot be reset without a matching confirmation', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-completely-new-password',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('password cannot be reset with an expired token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        /*
         * The broker treats a token older than auth.passwords.users.expire minutes as
         * missing, so an expired link reports the same "invalid token" error on the email
         * field as a forged one. Travelling past the window is the only way to reach it.
         */
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->travelBack();

        expect(Hash::check('brand-new-password', $user->refresh()->password))->toBeFalse();
        expect(Hash::check('password', $user->password))->toBeTrue();

        return true;
    });
});

test('an expired reset link can no longer render the reset screen data usefully', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        /*
         * Fortify's reset screen is stateless, so it still renders; the token is only
         * checked on submit. Asserting that keeps the expiry behaviour documented.
         */
        $this->get(route('password.reset', $notification->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/ResetPassword'));

        $this->travelBack();

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
