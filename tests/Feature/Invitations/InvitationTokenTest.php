<?php

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Invitation tokens are stored hashed
|--------------------------------------------------------------------------
|
| The security property this file exists for: a dump of the database yields no usable
| invitation link. `invitations.token` holds a sha256 hash, and the plain text lives only
| in the email that was sent.
|
| Everything else here follows from that. A token cannot be recovered, so resending has to
| mint a new one, which means the previously emailed link stops working — asserted below
| rather than left as folklore, because a future "let's just email the stored token again"
| would look like a simplification and would silently store plain text.
|
| The token is read back out of the message the application really sent (MAIL_MAILER=array
| in phpunit.xml), not out of a faked notification: a fake would still pass if the mail body
| never contained the link.
|
*/

test('the plain-text token is emailed and appears nowhere in the database', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => 'invited@example.com',
        'role' => UserRole::Member->value,
    ])->assertRedirect(route('admin.invitations.index'));

    $token = invitationTokenFromLastEmail();
    $invitation = Invitation::query()->sole();

    expect($token)->toHaveLength(64)
        ->and($invitation->token)->toBe(Invitation::hashToken($token))
        ->and($invitation->token)->not->toBe($token);

    /*
     * Every column of every row, compared as a string. Checking only `token` would miss a future
     * `plain_token` column, a debug copy on `email`, or a token that leaked into `users`.
     */
    foreach (['invitations', 'users'] as $table) {
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                expect((string) $value)->not->toContain(
                    $token,
                    "The plain-text token leaked into {$table}.{$column}.",
                );
            }
        }
    }
});

test('the token is hidden from serialisation', function () {
    [$invitation] = invitationWithToken();

    expect($invitation->toArray())->not->toHaveKey('token')
        ->and(json_decode((string) json_encode($invitation), true))->not->toHaveKey('token');
});

test('hashing a token is deterministic and matches what acceptance looks up', function () {
    [$invitation, $token] = invitationWithToken();

    expect(Invitation::hashToken($token))->toBe(Invitation::hashToken($token))
        ->and(Invitation::hashToken($token))->toBe($invitation->token)
        ->and(Invitation::hashToken($token.'x'))->not->toBe($invitation->token);
});

test('resending changes the stored hash so the previous link stops working', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.invitations.store'), [
        'email' => 'invited@example.com',
        'role' => UserRole::Member->value,
    ]);

    $firstToken = invitationTokenFromLastEmail();
    $invitation = Invitation::query()->sole();
    $firstHash = $invitation->token;

    $this->actingAs($admin)
        ->post(route('admin.invitations.resend', ['invitation' => $invitation]))
        ->assertRedirect(route('admin.invitations.index'));

    $secondToken = invitationTokenFromLastEmail();
    $invitation->refresh();

    expect($secondToken)->not->toBe($firstToken)
        ->and($invitation->token)->not->toBe($firstHash)
        ->and($invitation->token)->toBe(Invitation::hashToken($secondToken));

    /*
     * The acceptance routes are guest-only, and `actingAs()` above is still in effect, so the
     * administrator has to be signed out before following either link — otherwise `guest` redirects
     * and the assertion below would be about the wrong thing.
     */
    Auth::logout();

    /*
     * The old link is not merely stale, it is gone: its hash is no longer in the table, so it is
     * indistinguishable from a token that never existed.
     */
    $this->get(route('invitations.show', ['token' => $firstToken]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Invalid')
            ->where('reason', 'unknown'),
        );

    $this->post(route('invitations.store', ['token' => $firstToken]), [
        'name' => 'Uninvited Person',
        'password' => 'a-really-good-password',
        'password_confirmation' => 'a-really-good-password',
    ])->assertInertia(fn (Assert $page) => $page->component('invitations/Invalid'));

    expect(User::query()->where('email', 'invited@example.com')->exists())->toBeFalse();

    /* The new link, by contrast, works. */
    $this->get(route('invitations.show', ['token' => $secondToken]))
        ->assertInertia(fn (Assert $page) => $page->component('invitations/Accept'));
});

test('two invitations never share a token', function () {
    $tokens = collect(range(1, 5))->map(function (int $index): string {
        [, $token] = invitationWithToken(['email' => "person{$index}@example.com"]);

        return $token;
    });

    expect($tokens->unique())->toHaveCount(5)
        ->and(Invitation::query()->pluck('token')->unique())->toHaveCount(5);
});
