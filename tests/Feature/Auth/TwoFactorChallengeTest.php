<?php

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

/**
 * Log in far enough to be sitting on the two factor challenge, and return the user.
 */
function challengedUser(): User
{
    $user = User::factory()->withTwoFactor()->create();

    test()->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    test()->assertGuest();

    return $user;
}

/**
 * Get the TOTP code that the given user's authenticator app would currently display.
 */
function challengeTotpCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(
        Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret)
    );
}

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    challengedUser();

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/TwoFactorChallenge'),
        );
});

test('a valid totp code completes the challenge', function () {
    $user = challengedUser();

    $this->post(route('two-factor.login.store'), ['code' => challengeTotpCode($user)])
        ->assertSessionHasNoErrors()
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
});

test('an invalid totp code does not complete the challenge', function () {
    Event::fake([TwoFactorAuthenticationFailed::class]);

    challengedUser();

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');

    $this->assertGuest();

    Event::assertDispatched(TwoFactorAuthenticationFailed::class);
});

test('a valid recovery code completes the challenge and is consumed', function () {
    $user = challengedUser();

    /** @var array<int, string> $codes */
    $codes = json_decode(
        Fortify::currentEncrypter()->decrypt((string) $user->two_factor_recovery_codes),
        true
    );

    $this->post(route('two-factor.login.store'), ['recovery_code' => $codes[0]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);

    /** @var array<int, string> $remaining */
    $remaining = json_decode(
        Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_recovery_codes),
        true
    );

    expect($remaining)->not->toContain($codes[0])->toHaveCount(8);
});

test('an invalid recovery code does not complete the challenge', function () {
    challengedUser();

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
});

test('the challenge cannot be completed without a code', function () {
    challengedUser();

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'))
        ->assertSessionHasErrors();

    $this->assertGuest();
});

test('the challenge is not reachable once authenticated', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('two-factor.login'))
        ->assertRedirect(config('fortify.home'));
});
