<?php

use App\Models\Session;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The sessions administration screen
|--------------------------------------------------------------------------
|
| Every session here is addressed by `digest()` and never by its identifier — the identifier is
| the live value in that browser's session cookie. `SessionIdentifierTest` is the file that
| proves the identifier does not escape; this one covers the behaviour built on top of that.
|
| Two asymmetries worth remembering:
|
| - signing out **your own** session through this screen is a 403, not a logout. The screen
|   exists to remove other people's access, and there is a bulk action for "everybody but me".
| - `destroyOthers()` includes guest session rows, because a global sign-out that left rows
|   behind would not be one — while `index()` excludes them, because "somebody loaded the
|   login page" is not a signed-in browser.
|
| `TestCase::pinSessionId()` is what makes the current-session cases testable at all: with
| `SESSION_DRIVER=array` the harness sends no session cookie back, so StartSession mints a new
| identifier on every request and no `sessions` row would ever correspond to the request being
| made. See the docblock on that method.
|
*/

/**
 * Every route on the screen, as [method, url, payload] triples.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
 */
function sessionAdminRoutes(string $digest): array
{
    return [
        'index' => ['get', route('admin.sessions.index'), []],
        'destroy' => ['delete', route('admin.sessions.destroy'), ['digest' => $digest]],
        'destroy-others' => ['delete', route('admin.sessions.destroy-others'), []],
    ];
}

test('a guest is redirected to login from every session route', function () {
    $session = Session::factory()->create();

    foreach (sessionAdminRoutes($session->digest()) as [$method, $url, $payload]) {
        $this->{$method}($url, $payload)->assertRedirect(route('login'));
    }

    expect(Session::query()->count())->toBe(1);
});

test('a member is forbidden from every session route', function () {
    $member = User::factory()->create();
    $session = Session::factory()->create();

    foreach (sessionAdminRoutes($session->digest()) as [$method, $url, $payload]) {
        $this->actingAs($member)->{$method}($url, $payload)->assertForbidden();
    }

    expect(Session::query()->count())->toBe(1);
});

test('an unverified administrator is sent to email verification', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $session = Session::factory()->create();

    foreach (sessionAdminRoutes($session->digest()) as [$method, $url, $payload]) {
        $this->actingAs($admin)->{$method}($url, $payload)->assertRedirect(route('verification.notice'));
    }

    expect(Session::query()->count())->toBe(1);
});

test('the list shows each session with its account, address, browser, platform and last activity', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $member = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    $recent = Session::factory()->for($member)->create([
        'ip_address' => '198.51.100.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
        'last_activity' => now()->subMinutes(2)->getTimestamp(),
    ]);

    Session::factory()->for($admin)->create([
        'ip_address' => '203.0.113.42',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
        'last_activity' => now()->subHours(3)->getTimestamp(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/sessions/Index')
            ->has('sessions', 2)
            /* Ordered by last activity, most recent first. */
            ->where('sessions.0.digest', $recent->digest())
            ->where('sessions.0.user_name', 'Grace Hopper')
            ->where('sessions.0.user_email', 'grace@example.com')
            ->where('sessions.0.ip_address', '198.51.100.7')
            ->where('sessions.0.browser', 'Edge')
            ->where('sessions.0.platform', 'Windows')
            ->where('sessions.0.last_active_at', $recent->lastActiveAt()->toDayDateTimeString())
            ->where('sessions.0.last_active_at_diff', $recent->lastActiveAt()->diffForHumans())
            ->where('sessions.0.is_current', false)
            ->where('sessions.1.user_name', 'Ada Lovelace')
            ->where('sessions.1.ip_address', '203.0.113.42')
            ->where('sessions.1.browser', 'Safari')
            ->where('sessions.1.platform', 'iOS')
            ->where('sessions.1.is_current', false),
        );
});

test('the list leaves out sessions that belong to nobody', function () {
    $admin = User::factory()->admin()->create();

    $signedIn = Session::factory()->for($admin)->create();
    Session::factory()->guest()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('admin.sessions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.digest', $signedIn->digest()),
        );
});

test('the list marks the session making the request as the current one', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    $current = Session::factory()->for($admin)->create([
        'id' => $currentSessionId,
        'last_activity' => now()->getTimestamp(),
    ]);
    $other = Session::factory()->for($admin)->create([
        'last_activity' => now()->subHour()->getTimestamp(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.sessions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 2)
            ->where('sessions.0.digest', $current->digest())
            ->where('sessions.0.is_current', true)
            ->where('sessions.1.digest', $other->digest())
            ->where('sessions.1.is_current', false),
        );
});

test('an administrator can sign out a single session', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $target = Session::factory()->for($member)->create();
    $kept = Session::factory()->for($member)->create();

    $response = $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $target->digest()]);

    $response->assertRedirect(route('admin.sessions.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper was signed out of that browser.',
    ]);

    expect(Session::query()->whereKey($target->getKey())->exists())->toBeFalse()
        ->and(Session::query()->whereKey($kept->getKey())->exists())->toBeTrue();
});

test('an administrator cannot sign out their own session', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    $current = Session::factory()->for($admin)->create(['id' => $currentSessionId]);

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $current->digest()])
        ->assertForbidden();

    expect(Session::query()->whereKey($current->getKey())->exists())->toBeTrue();
});

test('an administrator can sign out another of their own browsers', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    $current = Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    $otherBrowser = Session::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $otherBrowser->digest()])
        ->assertRedirect(route('admin.sessions.index'));

    expect(Session::query()->whereKey($otherBrowser->getKey())->exists())->toBeFalse()
        ->and(Session::query()->whereKey($current->getKey())->exists())->toBeTrue();
});

test('signing out a session that no longer exists is a 404', function () {
    $admin = User::factory()->admin()->create();

    $vanished = Session::factory()->for($admin)->create();
    $digest = $vanished->digest();
    $vanished->delete();

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $digest])
        ->assertNotFound();
});

test('the digest must look like a sha256 digest', function (mixed $digest) {
    $admin = User::factory()->admin()->create();
    Session::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->from(route('admin.sessions.index'))
        ->delete(route('admin.sessions.destroy'), ['digest' => $digest])
        ->assertSessionHasErrors('digest');

    expect(Session::query()->count())->toBe(1);
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => ['abc123'],
    'not hex' => [['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz']],
    'uppercase hex' => ['ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789'],
    'a session identifier instead of its digest' => ['AbCdEf0123456789AbCdEf0123456789AbCdEf01'],
]);

test('a digest that resolves to no session cannot be brute forced into one', function () {
    $admin = User::factory()->admin()->create();
    $session = Session::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => str_repeat('0', 64)])
        ->assertNotFound();

    expect(Session::query()->whereKey($session->getKey())->exists())->toBeTrue();
});

test('signing out all other sessions keeps only the one making the request', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $currentSessionId = $this->pinSessionId();
    $current = Session::factory()->for($admin)->create(['id' => $currentSessionId]);

    Session::factory()->count(2)->for($member)->create();
    Session::factory()->for($admin)->create();
    Session::factory()->guest()->create();

    expect(Session::query()->count())->toBe(5);

    $response = $this->actingAs($admin)->delete(route('admin.sessions.destroy-others'));

    $response->assertRedirect(route('admin.sessions.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => '4 other sessions were signed out.',
    ]);

    expect(Session::query()->count())->toBe(1)
        ->and(Session::query()->whereKey($current->getKey())->exists())->toBeTrue();
});

test('signing out all other sessions when there are none says so and changes nothing', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    Session::factory()->for($admin)->create(['id' => $currentSessionId]);

    $response = $this->actingAs($admin)->delete(route('admin.sessions.destroy-others'));

    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'There were no other sessions to sign out.',
    ]);

    expect(Session::query()->count())->toBe(1);
});

test('signing out all other sessions reports a single session in the singular', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    Session::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy-others'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'One other session was signed out.',
        ]);

    expect(Session::query()->count())->toBe(1);
});

test('the administrator stays signed in after signing everybody else out', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $currentSessionId = $this->pinSessionId();
    Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    Session::factory()->for($member)->create();

    $this->actingAs($admin)->delete(route('admin.sessions.destroy-others'));

    $this->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.is_current', true),
        );
});

test('a session whose account has vanished still lists and can be signed out', function () {
    /*
     * Reachable because `sessions.user_id` has no foreign key: a row can outlive the account it
     * names if anything ever deletes a user without going through the accounts screen.
     */
    $admin = User::factory()->admin()->create();
    $orphan = Session::factory()->for(User::factory())->create();

    User::query()->whereKey($orphan->user_id)->delete();

    $this->actingAs($admin)
        ->get(route('admin.sessions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.user_name', null)
            ->where('sessions.0.user_email', null),
        );

    $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $orphan->digest()])
        ->assertRedirect(route('admin.sessions.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'That session was signed out.',
        ]);

    expect(Session::query()->count())->toBe(0);
});

test('the screen renders with nothing signed in', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/sessions/Index')
            ->has('sessions', 0),
        );
});
