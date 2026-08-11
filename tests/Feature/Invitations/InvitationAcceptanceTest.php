<?php

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Accepting an invitation
|--------------------------------------------------------------------------
|
| The only way an account is created, so this is where the properties that make that safe
| are pinned:
|
| - the role comes from the invitation and cannot be posted (`role` is not mass-assignable,
|   and the controller assigns it explicitly afterwards);
| - the email address comes from the invitation, so the read-only field on the form is
|   read-only in fact and not just in the markup;
| - the new account is **unverified**. Clicking a mailed link proves somebody read the
|   mailbox, not that the person filling in this form controls it, so the ordinary
|   verification flow still runs. This one looks like a bug until you know why — see
|   `.ai/rules/auth.md`.
|
| A link that cannot be used renders `invitations/Invalid` with one of three reasons, and
| each reason has its own test: "I mistyped it", "this is old" and "I already used this"
| need three different answers.
|
*/

const ACCEPTANCE_PASSWORD = 'an-invitation-password';

/**
 * The valid body of an acceptance request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function acceptancePayload(array $overrides = []): array
{
    return [
        'name' => 'Invited Person',
        'password' => ACCEPTANCE_PASSWORD,
        'password_confirmation' => ACCEPTANCE_PASSWORD,
        ...$overrides,
    ];
}

test('the acceptance form shows the invited address ready to be used', function () {
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $response = $this->get(route('invitations.show', ['token' => $token]));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Accept')
        ->where('email', 'invited@example.com')
        ->where('token', $token)
        ->where('roleLabel', UserRole::Member->label())
        ->has('expiresAtDiff'),
    );

    expect($invitation->isPending())->toBeTrue();
});

test('accepting an invitation creates an account with the invited role', function () {
    [$invitation, $token] = invitationWithToken([
        'email' => 'invited@example.com',
        'role' => UserRole::Admin,
    ]);

    $response = $this->post(route('invitations.store', ['token' => $token]), acceptancePayload());

    $response->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'invited@example.com')->sole();

    expect($user->name)->toBe('Invited Person')
        ->and($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check(ACCEPTANCE_PASSWORD, $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);

    $invitation->refresh();

    expect($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->isAccepted())->toBeTrue()
        ->and($invitation->isPending())->toBeFalse();
});

test('accepting an invitation does not verify the email address', function () {
    Notification::fake();

    [, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload());

    $user = User::query()->where('email', 'invited@example.com')->sole();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    /*
     * `Registered` fired, which is what puts the account into the standard verification flow: the
     * framework's listener sends the notification. Asserting the notification rather than the event
     * proves the flow is actually wired up, not merely that an event object was dispatched.
     */
    Notification::assertSentTo($user, VerifyEmail::class);

    /* And the application is genuinely closed to them until they finish it. */
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('acceptance signs the user in on a regenerated session', function () {
    [, $token] = invitationWithToken();

    /*
     * The session id has to be sampled from *inside* the request, and the `Login` event is the place
     * to do it: the controller signs the user in and then regenerates, so the id at that moment is
     * the pre-regeneration one, and the id the session store is left holding afterwards is the
     * post-regeneration one.
     *
     * Comparing ids across two separate test requests instead would assert nothing at all — each
     * test request arrives without a session cookie, so `StartSession` gives it a fresh id whether
     * the controller regenerates or not. That version of this test passes with the `regenerate()`
     * call deleted.
     */
    $idWhenSignedIn = null;

    Event::listen(Login::class, function () use (&$idWhenSignedIn): void {
        $idWhenSignedIn = Session::getId();
    });

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload())
        ->assertRedirect(route('dashboard'));

    expect($idWhenSignedIn)->not->toBeNull()
        ->and(Session::getId())->not->toBe($idWhenSignedIn);

    /* Regenerated rather than flushed: the sign-in survives the new id. */
    $this->assertAuthenticated();
});

test('a posted role is ignored, so an invitation cannot be upgraded by the person accepting it', function () {
    [, $token] = invitationWithToken([
        'email' => 'invited@example.com',
        'role' => UserRole::Member,
    ]);

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload([
        'role' => UserRole::Admin->value,
    ]))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'invited@example.com')->sole();

    expect($user->role)->toBe(UserRole::Member)
        ->and($user->isAdmin())->toBeFalse();
});

test('a posted email address is ignored, so an invitation cannot be redirected to another mailbox', function () {
    [, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload([
        'email' => 'somebody-else@example.com',
    ]))->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'invited@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'somebody-else@example.com')->exists())->toBeFalse();
});

test('acceptance validates the name and password through the shared rules', function (array $payload, string $field) {
    [$invitation, $token] = invitationWithToken();

    $response = $this->post(route('invitations.store', ['token' => $token]), $payload);

    $response->assertSessionHasErrors($field);

    expect(User::query()->count())->toBe(1) /* only the administrator the factory made */
        ->and($invitation->fresh()?->isPending())->toBeTrue();

    $this->assertGuest();
})->with([
    'no name' => [fn () => acceptancePayload(['name' => '']), 'name'],
    'no password' => [fn () => acceptancePayload(['password' => '', 'password_confirmation' => '']), 'password'],
    'mismatched confirmation' => [fn () => acceptancePayload(['password_confirmation' => 'something-else']), 'password'],
]);

test('an unknown token cannot create an account and says so', function () {
    $response = $this->get(route('invitations.show', ['token' => Invitation::generateToken()]));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Invalid')
        ->where('reason', 'unknown')
        ->where('title', 'This invitation link is not valid')
        ->has('description'),
    );

    $this->post(route('invitations.store', ['token' => Invitation::generateToken()]), acceptancePayload())
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Invalid')
            ->where('reason', 'unknown'),
        );

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

test('an expired token cannot create an account and says so', function () {
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $invitation->expires_at = now()->subMinute();
    $invitation->save();

    $response = $this->get(route('invitations.show', ['token' => $token]));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Invalid')
        ->where('reason', 'expired')
        ->where('title', 'This invitation link has expired'),
    );

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload())
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Invalid')
            ->where('reason', 'expired'),
        );

    expect(User::query()->where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()?->isAccepted())->toBeFalse();
    $this->assertGuest();
});

test('an already accepted token cannot create a second account and says so', function () {
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload())
        ->assertRedirect(route('dashboard'));

    Auth::logout();

    $response = $this->get(route('invitations.show', ['token' => $token]));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Invalid')
        ->where('reason', 'accepted')
        ->where('title', 'This invitation has already been used'),
    );

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload([
        'name' => 'Second Person',
    ]))->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Invalid')
        ->where('reason', 'accepted'),
    );

    expect(User::query()->where('email', 'invited@example.com')->count())->toBe(1)
        ->and(User::query()->where('name', 'Second Person')->exists())->toBeFalse()
        ->and($invitation->fresh()?->accepted_at)->not->toBeNull();
    $this->assertGuest();
});

test('an expired invitation that was accepted still reports having been used', function () {
    [$invitation, $token] = invitationWithToken();

    $invitation->accepted_at = now()->subMonth();
    $invitation->expires_at = now()->subWeek();
    $invitation->save();

    $this->get(route('invitations.show', ['token' => $token]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Invalid')
            ->where('reason', 'accepted'),
        );
});

test('a signed-in user is sent to their dashboard instead of accepting an invitation', function () {
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('invitations.show', ['token' => $token]))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($member)
        ->post(route('invitations.store', ['token' => $token]), acceptancePayload())
        ->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()?->isPending())->toBeTrue();

    $this->assertAuthenticatedAs($member);
});

test('acceptance attempts are throttled', function () {
    $token = Invitation::generateToken();

    foreach (range(1, 6) as $ignored) {
        $this->post(route('invitations.store', ['token' => $token]), acceptancePayload())
            ->assertOk();
    }

    $this->post(route('invitations.store', ['token' => $token]), acceptancePayload())
        ->assertStatus(429);
});
