<?php

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
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
 * Get the TOTP code that the given user's authenticator app would currently display.
 */
function currentTotpCode(User $user): string
{
    $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

    return app(Google2FA::class)->getCurrentOtp($secret);
}

test('two factor enrolment requires authentication', function () {
    $this->post(route('two-factor.enable'))->assertRedirect(route('login'));
    $this->get(route('two-factor.qr-code'))->assertRedirect(route('login'));
    $this->get(route('two-factor.recovery-codes'))->assertRedirect(route('login'));
});

test('two factor enrolment requires a confirmed password', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('password.confirm'));
});

test('enabling two factor stores an unconfirmed secret and recovery codes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_recovery_codes)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('the qr code and secret key are available during enrolment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $this->get(route('two-factor.qr-code'))
        ->assertOk()
        ->assertJsonStructure(['svg', 'url']);

    $secret = $this->get(route('two-factor.secret-key'))
        ->assertOk()
        ->assertJsonStructure(['secretKey'])
        ->json('secretKey');

    expect($secret)->toBe(Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret));
});

test('recovery codes are available during enrolment', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $codes = $this->get(route('two-factor.recovery-codes'))->assertOk()->json();

    expect($codes)->toBeArray()->toHaveCount(8);
});

test('recovery codes can be regenerated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $original = $this->get(route('two-factor.recovery-codes'))->json();

    $this->post(route('two-factor.regenerate-recovery-codes'))->assertSessionHasNoErrors();

    $regenerated = $this->get(route('two-factor.recovery-codes'))->json();

    expect($regenerated)->toHaveCount(8)->not->toBe($original);
});

test('two factor is confirmed with a valid totp code', function () {
    Event::fake([TwoFactorAuthenticationConfirmed::class]);

    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $this->post(route('two-factor.confirm'), ['code' => currentTotpCode($user->refresh())])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();

    Event::assertDispatched(TwoFactorAuthenticationConfirmed::class);
});

test('two factor is not confirmed with an invalid totp code', function () {
    Event::fake([TwoFactorAuthenticationConfirmed::class]);

    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $this->from(route('security.edit'))
        ->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    Event::assertNotDispatched(TwoFactorAuthenticationConfirmed::class);
});

test('two factor is not confirmed when no code is given', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $this->from(route('security.edit'))
        ->post(route('two-factor.confirm'), ['code' => ''])
        ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull();
});

test('an unconfirmed enrolment does not challenge the user at login', function () {
    $user = User::factory()->withUnconfirmedTwoFactor()->create();

    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    /*
     * With 'confirm' => true, a secret that was generated but never confirmed must not gate
     * sign-in, or a user who abandoned enrolment would be locked out of their own account.
     */
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('two factor can be disabled', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

test('the security page reports two factor as enabled once confirmed', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', true)
            ->where('requiresConfirmation', true),
        );
});
