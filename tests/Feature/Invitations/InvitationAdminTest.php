<?php

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The invitation administration screen
|--------------------------------------------------------------------------
|
| Inviting somebody decides what role their account will hold, so every one of these routes
| is administrator-only. The boundary is asserted per route and per actor — guest, member,
| unverified administrator — rather than once for the area: AdminAccessTest sweeps the route
| collection for the three middleware, and this file checks the behaviour they produce.
|
| The one asymmetry worth remembering: resending an accepted invitation is a 403, not a
| no-op. There is no link left to send (the stored token is a hash), the account already
| exists, and succeeding quietly would imply otherwise.
|
*/

/**
 * Every write route in the area, as [method, url factory] pairs.
 *
 * @return array<string, array{0: string, 1: Closure(Invitation): string}>
 */
function invitationAdminRoutes(): array
{
    return [
        'index' => ['get', fn (): string => route('admin.invitations.index')],
        'store' => ['post', fn (): string => route('admin.invitations.store')],
        'resend' => ['post', fn (Invitation $invitation): string => route('admin.invitations.resend', ['invitation' => $invitation])],
        'destroy' => ['delete', fn (Invitation $invitation): string => route('admin.invitations.destroy', ['invitation' => $invitation])],
    ];
}

test('a guest is redirected to login from every invitation route', function () {
    [$invitation] = invitationWithToken();

    foreach (invitationAdminRoutes() as [$method, $url]) {
        $this->{$method}($url($invitation))->assertRedirect(route('login'));
    }
});

test('a member is forbidden from every invitation route', function () {
    [$invitation] = invitationWithToken();

    $member = User::factory()->create();

    foreach (invitationAdminRoutes() as [$method, $url]) {
        $this->actingAs($member)->{$method}($url($invitation))->assertForbidden();
    }

    expect(Invitation::query()->whereKey($invitation->getKey())->exists())->toBeTrue();
});

test('an unverified administrator is sent to email verification', function () {
    [$invitation] = invitationWithToken();

    $admin = User::factory()->admin()->unverified()->create();

    $this->actingAs($admin)
        ->get(route('admin.invitations.index'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($admin)
        ->post(route('admin.invitations.resend', ['invitation' => $invitation]))
        ->assertRedirect(route('verification.notice'));
});

test('the list shows every invitation with its status, role, inviter and expiry', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    invitationWithToken(['email' => 'pending@example.com', 'invited_by_id' => $admin->id]);

    Invitation::factory()->accepted()->create([
        'email' => 'accepted@example.com',
        'invited_by_id' => $admin->id,
    ]);

    Invitation::factory()->expired()->forAdministrator()->withoutInviter()->create([
        'email' => 'expired@example.com',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.invitations.index'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/invitations/Index')
        ->has('invitations', 3)
        ->where('expiresAfterDays', Invitation::EXPIRES_AFTER_DAYS)
        ->has('roles', 2)
        ->has('invitations', fn (Assert $invitations) => $invitations
            ->each(fn (Assert $invitation) => $invitation
                ->has('id')
                ->has('email')
                ->has('role')
                ->has('role_label')
                ->has('status')
                ->has('status_label')
                ->has('invited_by')
                ->has('expires_at')
                ->has('expires_at_diff')
                ->has('accepted_at_diff')
                ->has('created_at_diff'),
            ),
        ),
    );

    /* The rows come back newest first, so index 0 is the expired administrator invitation. */
    $invitations = collect($response->viewData('page')['props']['invitations'])
        ->keyBy('email');

    expect($invitations['pending@example.com']['status'])->toBe('pending')
        ->and($invitations['pending@example.com']['status_label'])->toBe('Pending')
        ->and($invitations['pending@example.com']['role'])->toBe('member')
        ->and($invitations['pending@example.com']['role_label'])->toBe('Member')
        ->and($invitations['pending@example.com']['invited_by'])->toBe('Ada Lovelace')
        ->and($invitations['accepted@example.com']['status'])->toBe('accepted')
        ->and($invitations['accepted@example.com']['accepted_at_diff'])->not->toBeNull()
        ->and($invitations['expired@example.com']['status'])->toBe('expired')
        ->and($invitations['expired@example.com']['status_label'])->toBe('Expired')
        ->and($invitations['expired@example.com']['role'])->toBe('admin')
        ->and($invitations['expired@example.com']['role_label'])->toBe('Administrator')
        ->and($invitations['expired@example.com']['invited_by'])->toBeNull();

    /* Nothing about the row leaks the token, hashed or otherwise. */
    expect($invitations['pending@example.com'])->not->toHaveKey('token');
});

test('an administrator invites an email address and the invitation is emailed', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => 'invited@example.com',
        'role' => UserRole::Member->value,
    ]);

    $response->assertRedirect(route('admin.invitations.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Invitation sent to invited@example.com.',
    ]);

    $invitation = Invitation::query()->sole();

    expect($invitation->email)->toBe('invited@example.com')
        ->and($invitation->role)->toBe(UserRole::Member)
        ->and($invitation->invited_by_id)->toBe($admin->id)
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->isPending())->toBeTrue()
        ->and($invitation->expires_at->isAfter(now()->addDays(Invitation::EXPIRES_AFTER_DAYS - 1)))->toBeTrue();

    Notification::assertSentTo($invitation, InvitationNotification::class);
});

test('an administrator can invite another administrator', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => 'invited@example.com',
        'role' => UserRole::Admin->value,
    ])->assertRedirect(route('admin.invitations.index'));

    expect(Invitation::query()->sole()->role)->toBe(UserRole::Admin);
});

test('inviting an address is validated', function (array $payload, string $field) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.invitations.store'), $payload)
        ->assertSessionHasErrors($field);

    expect(Invitation::query()->count())->toBe(0);
})->with([
    'no email' => [['role' => 'member'], 'email'],
    'malformed email' => [['email' => 'not-an-email', 'role' => 'member'], 'email'],
    'no role' => [['email' => 'invited@example.com'], 'role'],
    'unknown role' => [['email' => 'invited@example.com', 'role' => 'superuser'], 'role'],
]);

test('an address that already has an account cannot be invited', function () {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create(['email' => 'already@example.com']);

    $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => $existing->email,
        'role' => UserRole::Member->value,
    ])->assertSessionHasErrors(['email' => 'That email address already has an account.']);

    expect(Invitation::query()->count())->toBe(0);
});

test('re-inviting an address reuses the row and clears a previous acceptance', function () {
    $admin = User::factory()->admin()->create();

    $invitation = Invitation::factory()->accepted()->expired()->create([
        'email' => 'invited@example.com',
        'role' => UserRole::Member,
    ]);

    $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => 'invited@example.com',
        'role' => UserRole::Admin->value,
    ])->assertRedirect(route('admin.invitations.index'));

    expect(Invitation::query()->count())->toBe(1);

    $invitation->refresh();

    expect($invitation->accepted_at)->toBeNull()
        ->and($invitation->role)->toBe(UserRole::Admin)
        ->and($invitation->invited_by_id)->toBe($admin->id)
        ->and($invitation->isPending())->toBeTrue();
});

test('an administrator resends a pending invitation', function () {
    Notification::fake();

    $originalInviter = User::factory()->admin()->create();
    $admin = User::factory()->admin()->create();
    [$invitation] = invitationWithToken([
        'email' => 'invited@example.com',
        'invited_by_id' => $originalInviter->id,
    ]);

    $response = $this->actingAs($admin)
        ->post(route('admin.invitations.resend', ['invitation' => $invitation]));

    $response->assertRedirect(route('admin.invitations.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'A new invitation link was sent to invited@example.com.',
    ]);

    expect(Invitation::query()->count())->toBe(1);

    /*
     * `invited_by_id` follows the live link: the administrator who resent it is the one whose
     * invitation is now outstanding, and the link the original inviter sent no longer works.
     */
    expect($invitation->fresh()?->invited_by_id)->toBe($admin->id);

    Notification::assertSentTo($invitation->fresh(), InvitationNotification::class);
});

test('resending an expired invitation brings it back into the pending window', function () {
    $admin = User::factory()->admin()->create();

    [$invitation] = invitationWithToken(['email' => 'invited@example.com']);
    $invitation->expires_at = now()->subWeek();
    $invitation->save();

    expect($invitation->isExpired())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('admin.invitations.resend', ['invitation' => $invitation]))
        ->assertRedirect(route('admin.invitations.index'));

    $invitation->refresh();

    expect($invitation->isExpired())->toBeFalse()
        ->and($invitation->isPending())->toBeTrue();
});

test('resending an accepted invitation is forbidden and sends nothing', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();

    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);
    $invitation->accepted_at = now();
    $invitation->save();

    $originalHash = $invitation->token;

    $this->actingAs($admin)
        ->post(route('admin.invitations.resend', ['invitation' => $invitation]))
        ->assertForbidden();

    $invitation->refresh();

    expect($invitation->token)->toBe($originalHash)
        ->and($invitation->token)->toBe(Invitation::hashToken($token))
        ->and($invitation->accepted_at)->not->toBeNull();

    Notification::assertNothingSent();
});

test('an administrator revokes an invitation and its link stops working', function () {
    $admin = User::factory()->admin()->create();
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $response = $this->actingAs($admin)
        ->delete(route('admin.invitations.destroy', ['invitation' => $invitation]));

    $response->assertRedirect(route('admin.invitations.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'The invitation for invited@example.com was revoked.',
    ]);

    expect(Invitation::query()->count())->toBe(0);

    Auth::logout();

    $this->get(route('invitations.show', ['token' => $token]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Invalid')
            ->where('reason', 'unknown'),
        );
});

test('revoking an accepted invitation removes the record but not the account', function () {
    $admin = User::factory()->admin()->create();
    [$invitation, $token] = invitationWithToken(['email' => 'invited@example.com']);

    $this->post(route('invitations.store', ['token' => $token]), [
        'name' => 'Invited Person',
        'password' => 'an-invitation-password',
        'password_confirmation' => 'an-invitation-password',
    ])->assertRedirect(route('dashboard'));

    $this->actingAs($admin)
        ->delete(route('admin.invitations.destroy', ['invitation' => $invitation]))
        ->assertRedirect(route('admin.invitations.index'));

    expect(Invitation::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'invited@example.com')->exists())->toBeTrue();
});

test('deleting the administrator who invited somebody leaves the invitation standing', function () {
    $admin = User::factory()->admin()->create();
    [$invitation] = invitationWithToken(['invited_by_id' => $admin->id]);

    expect($invitation->invitedBy?->id)->toBe($admin->id);

    $admin->delete();
    $invitation->refresh();

    expect($invitation->exists)->toBeTrue()
        ->and($invitation->invited_by_id)->toBeNull()
        ->and($invitation->invitedBy)->toBeNull()
        ->and($invitation->isPending())->toBeTrue();
});

test('the pending scope excludes accepted and expired invitations', function () {
    invitationWithToken(['email' => 'pending@example.com']);
    Invitation::factory()->accepted()->create(['email' => 'accepted@example.com']);
    Invitation::factory()->expired()->create(['email' => 'expired@example.com']);

    expect(Invitation::query()->pending()->pluck('email')->all())->toBe(['pending@example.com']);
});
