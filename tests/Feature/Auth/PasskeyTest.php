<?php

use App\Models\User;
use Database\Factories\PasskeyFactory;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::passkeys());
});

/**
 * Build a structurally valid WebAuthn assertion payload.
 *
 * The bytes are not cryptographically valid; they only need to survive the deserialization
 * in Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest so the request reaches the
 * VerifyPasskey action. Signature verification itself belongs to laravel/passkeys.
 *
 * @return array<string, mixed>
 */
function assertionCredential(string $credentialId): array
{
    $base64Url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return [
        'id' => $base64Url($credentialId),
        'rawId' => $base64Url($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => $base64Url((string) json_encode([
                'type' => 'webauthn.get',
                'challenge' => $base64Url('challenge'),
                'origin' => config('app.url'),
            ])),
            'authenticatorData' => $base64Url(str_repeat("\x00", 32).chr(0x01).pack('N', 1)),
            'signature' => $base64Url('signature'),
            'userHandle' => $base64Url('user-handle'),
        ],
    ];
}

test('the well-known passkey endpoints document the enroll and manage urls', function () {
    $this->get(route('well-known.passkeys'))
        ->assertOk()
        ->assertExactJson([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
});

test('guests can request passkey sign-in options', function () {
    $this->get(route('passkey.login-options'))
        ->assertOk()
        ->assertJsonStructure(['options' => ['challenge', 'rpId']]);

    expect(session()->has('passkey.verification_options'))->toBeTrue();
});

test('authenticated users cannot request passkey sign-in options', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('passkey.login-options'))
        ->assertRedirect(config('fortify.home'));
});

test('passkey sign-in fails when the credential is not recognized', function () {
    $this->get(route('passkey.login-options'))->assertOk();

    $response = $this->post(route('passkey.login'), [
        'credential' => assertionCredential('unknown-credential'),
    ]);

    $response->assertSessionHasErrors('credential');
    $this->assertGuest();
});

test('passkey sign-in fails when the ceremony options are missing from the session', function () {
    $response = $this->post(route('passkey.login'), [
        'credential' => assertionCredential('some-credential'),
    ]);

    $response->assertSessionHasErrors('credential');
    $this->assertGuest();
});

test('passkey sign-in fails when the credential payload is malformed', function () {
    $response = $this->post(route('passkey.login'), [
        'credential' => ['id' => 'nope'],
    ]);

    $response->assertSessionHasErrors('credential.rawId');
    $this->assertGuest();
});

test('a verified passkey signs the user in and redirects to the dashboard', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create();

    /*
     * VerifyPasskey performs the WebAuthn signature check, which cannot be forged from a
     * test without a real authenticator. Swapping it out keeps this test focused on the
     * application's own wiring: the guard logs the user in and Fortify redirects them.
     */
    $this->swap(VerifyPasskey::class, new class($passkey) extends VerifyPasskey
    {
        public function __construct(private Passkey $passkey) {}

        public function __invoke(
            PublicKeyCredential $credential,
            PublicKeyCredentialRequestOptions $options,
            ?PasskeyUser $user = null,
        ): Passkey {
            return $this->passkey;
        }
    });

    $this->get(route('passkey.login-options'))->assertOk();

    $this->post(route('passkey.login'), [
        'credential' => assertionCredential('recognized-credential'),
    ])->assertRedirect(config('passkeys.redirect'));

    $this->assertAuthenticatedAs($user);
});

test('the security page lists the users passkeys and hides other users passkeys', function () {
    $user = User::factory()->create();

    PasskeyFactory::new()->for($user)->create(['name' => 'MacBook Pro']);
    PasskeyFactory::new()->for($user)->used()->create(['name' => 'iPhone']);
    PasskeyFactory::new()->for(User::factory()->create())->create(['name' => 'Someone Else']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('settings/Security')
                ->where('canManagePasskeys', true)
                ->has('passkeys', 2);

            $passkeys = collect($page->toArray()['props']['passkeys']);

            expect($passkeys->pluck('name')->sort()->values()->all())
                ->toBe(['MacBook Pro', 'iPhone']);

            expect($passkeys->firstWhere('name', 'iPhone')['last_used_at_diff'])->not->toBeNull();
            expect($passkeys->firstWhere('name', 'MacBook Pro')['last_used_at_diff'])->toBeNull();
        });
});

test('registering a passkey requires authentication', function () {
    $this->get(route('passkey.registration-options'))->assertRedirect(route('login'));
    $this->post(route('passkey.store'))->assertRedirect(route('login'));
});

test('registering a passkey requires a confirmed password', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('passkey.registration-options'))
        ->assertRedirect(route('password.confirm'));
});

test('registration options are issued once the password has been confirmed', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('passkey.registration-options'))
        ->assertOk()
        ->assertJsonStructure(['options' => ['challenge', 'rp', 'user']]);
});

test('a passkey can be renamed', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->put(route('passkey.update', $passkey), ['name' => 'New name'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect($passkey->refresh()->name)->toBe('New name');
});

test('renaming a passkey requires a name', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->put(route('passkey.update', $passkey), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect($passkey->refresh()->name)->toBe('Old name');
});

test('renaming a passkey requires authentication', function () {
    $passkey = PasskeyFactory::new()->create();

    $this->put(route('passkey.update', $passkey), ['name' => 'New name'])
        ->assertRedirect(route('login'));
});

test('renaming a passkey requires a confirmed password', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create();

    $this->actingAs($user)
        ->put(route('passkey.update', $passkey), ['name' => 'New name'])
        ->assertRedirect(route('password.confirm'));
});

test('a user cannot rename another users passkey', function () {
    $passkey = PasskeyFactory::new()->for(User::factory()->create())->create(['name' => 'Old name']);

    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('passkey.update', $passkey), ['name' => 'New name'])
        ->assertForbidden();

    expect($passkey->refresh()->name)->toBe('Old name');
});

test('a passkey can be deleted', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect();

    expect(Passkey::whereKey($passkey->getKey())->exists())->toBeFalse();
});

test('deleting a passkey requires a confirmed password', function () {
    $user = User::factory()->create();
    $passkey = PasskeyFactory::new()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('password.confirm'));

    expect(Passkey::whereKey($passkey->getKey())->exists())->toBeTrue();
});

test('a user cannot delete another users passkey', function () {
    $passkey = PasskeyFactory::new()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey))
        ->assertForbidden();

    expect(Passkey::whereKey($passkey->getKey())->exists())->toBeTrue();
});

test('the passkey rename route is registered', function () {
    expect(Route::has('passkey.update'))->toBeTrue();
});
