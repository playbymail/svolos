<?php

use App\Enums\UserRole;
use App\Models\Session;
use App\Models\User;
use Database\Factories\PasskeyFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;

/*
|--------------------------------------------------------------------------
| The accounts administration screen
|--------------------------------------------------------------------------
|
| This is the only screen in the application allowed to write `users.role`, and the only one
| that deletes somebody else's account, so the boundary is asserted per route and per actor:
| guest, member, unverified administrator, and — the case that matters most — administrator
| aiming at themselves.
|
| Self-targeting is a 403 rather than a confirmation dialog because it is the only thing
| standing between an installation and having no administrator at all. Recovering from that
| needs a shell on the server (`app:create-admin`), so it must not be reachable through the
| browser at all, not merely be discouraged.
|
| The other thing pinned here is the deletion contract, which is asymmetric on purpose:
| `sessions.user_id` carries no foreign key so session rows must be deleted explicitly, while
| `passkeys.user_id` has `cascadeOnDelete` so they must not be. Both halves are asserted, the
| passkey one so that nobody "fixes" a cascade that already works by duplicating it in PHP.
|
*/

/**
 * Every route on the screen, as [method, url factory] pairs.
 *
 * @return array<string, array{0: string, 1: Closure(User): string}>
 */
function userAdminRoutes(): array
{
    return [
        'index' => ['get', fn (): string => route('admin.users.index')],
        'role.update' => ['put', fn (User $user): string => route('admin.users.role.update', ['user' => $user])],
        'destroy' => ['delete', fn (User $user): string => route('admin.users.destroy', ['user' => $user])],
    ];
}

test('a guest is redirected to login from every account route', function () {
    $target = User::factory()->create();

    foreach (userAdminRoutes() as [$method, $url]) {
        $this->{$method}($url($target))->assertRedirect(route('login'));
    }

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
});

test('a member is forbidden from every account route', function () {
    $member = User::factory()->create();
    $target = User::factory()->create();

    foreach (userAdminRoutes() as [$method, $url]) {
        $this->actingAs($member)->{$method}($url($target), ['role' => 'admin'])->assertForbidden();
    }

    expect($target->fresh()?->role)->toBe(UserRole::Member);
});

test('a member cannot promote themselves through the account routes', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->put(route('admin.users.role.update', ['user' => $member]), ['role' => 'admin'])
        ->assertForbidden();

    expect($member->fresh()?->role)->toBe(UserRole::Member);
});

test('an unverified administrator is sent to email verification', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $target = User::factory()->create();

    foreach (userAdminRoutes() as [$method, $url]) {
        $this->actingAs($admin)
            ->{$method}($url($target), ['role' => 'admin'])
            ->assertRedirect(route('verification.notice'));
    }
});

test('the list shows every account with its role, verification, two-factor and session count', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $member = User::factory()->withTwoFactor()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $unverified = User::factory()->unverified()->create(['name' => 'Zoe Unverified']);

    Session::factory()->count(2)->for($member)->create();
    Session::factory()->for($unverified)->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/Index')
            ->has('users', 3)
            /* Ordered by name: Ada, Grace, Zoe. */
            ->where('users.0.name', 'Ada Lovelace')
            ->where('users.0.email', 'ada@example.com')
            ->where('users.0.role', 'admin')
            ->where('users.0.role_label', 'Administrator')
            ->where('users.0.email_verified', true)
            ->where('users.0.two_factor_enabled', false)
            ->where('users.0.sessions_count', 0)
            ->where('users.0.is_self', true)
            ->where('users.1.name', 'Grace Hopper')
            ->where('users.1.role', 'member')
            ->where('users.1.two_factor_enabled', true)
            ->where('users.1.sessions_count', 2)
            ->where('users.1.is_self', false)
            ->where('users.2.name', 'Zoe Unverified')
            ->where('users.2.email_verified', false)
            ->where('users.2.sessions_count', 1)
            ->where('users.0.created_at', $admin->created_at?->toDayDateTimeString())
            ->has('users.0.created_at_diff')
            ->has('roles', 2)
            ->where('roles.0.value', 'admin')
            ->where('roles.0.label', 'Administrator')
            ->where('roles.1.value', 'member')
            ->where('roles.1.label', 'Member'),
        );
});

test('the session count counts only that account\'s sessions', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada']);
    $member = User::factory()->create(['name' => 'Bob']);

    Session::factory()->count(3)->for($member)->create();
    Session::factory()->guest()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.0.name', 'Ada')
            ->where('users.0.sessions_count', 0)
            ->where('users.1.name', 'Bob')
            ->where('users.1.sessions_count', 3),
        );
});

test('an administrator can promote a member', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)
        ->put(route('admin.users.role.update', ['user' => $member]), ['role' => 'admin']);

    $response->assertRedirect(route('admin.users.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper is now Administrator.',
    ]);

    expect($member->fresh()?->role)->toBe(UserRole::Admin);
});

test('an administrator can demote another administrator', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.role.update', ['user' => $other]), ['role' => 'member'])
        ->assertRedirect(route('admin.users.index'));

    expect($other->fresh()?->role)->toBe(UserRole::Member);
});

test('an administrator cannot change their own role', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.role.update', ['user' => $admin]), ['role' => 'member'])
        ->assertForbidden();

    expect($admin->fresh()?->role)->toBe(UserRole::Admin);
});

test('the last administrator cannot demote themselves into an installation with no administrator', function () {
    $admin = User::factory()->admin()->create();

    expect(User::query()->where('role', UserRole::Admin)->count())->toBe(1);

    $this->actingAs($admin)
        ->put(route('admin.users.role.update', ['user' => $admin]), ['role' => 'member'])
        ->assertForbidden();

    expect(User::query()->where('role', UserRole::Admin)->count())->toBe(1);
});

test('the role must be one of the two application roles', function (mixed $role) {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->put(route('admin.users.role.update', ['user' => $member]), ['role' => $role])
        ->assertSessionHasErrors('role');

    expect($member->fresh()?->role)->toBe(UserRole::Member);
})->with([
    'missing' => [null],
    'empty' => [''],
    'unknown' => ['superadmin'],
    'a game role' => ['gamemaster'],
]);

test('the role endpoint changes nothing but the role', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    $this->actingAs($admin)->put(route('admin.users.role.update', ['user' => $member]), [
        'role' => 'admin',
        'name' => 'Someone Else',
        'email' => 'attacker@example.com',
        'password' => 'not-the-password',
    ]);

    $member->refresh();

    expect($member->role)->toBe(UserRole::Admin)
        ->and($member->name)->toBe('Grace Hopper')
        ->and($member->email)->toBe('grace@example.com');
});

test('an administrator can delete another account', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)
        ->delete(route('admin.users.destroy', ['user' => $member]));

    $response->assertRedirect(route('admin.users.index'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Grace Hopper's account was deleted.",
    ]);

    expect(User::query()->whereKey($member->getKey())->exists())->toBeFalse();
});

test('an administrator cannot delete their own account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', ['user' => $admin]))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->getKey())->exists())->toBeTrue();
});

test('deleting an account removes its session rows', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $memberSessions = Session::factory()->count(3)->for($member)->create();
    $adminSession = Session::factory()->for($admin)->create();
    $guestSession = Session::factory()->guest()->create();

    expect(Session::query()->where('user_id', $member->getKey())->count())->toBe(3);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', ['user' => $member]))
        ->assertRedirect(route('admin.users.index'));

    /*
     * Explicitly, not by cascade: `sessions.user_id` has no foreign key, so a row left behind
     * would be a browser still holding a live session cookie for an account that is gone.
     */
    foreach ($memberSessions as $session) {
        expect(Session::query()->whereKey($session->getKey())->exists())->toBeFalse();
    }

    expect(Session::query()->whereKey($adminSession->getKey())->exists())->toBeTrue()
        ->and(Session::query()->whereKey($guestSession->getKey())->exists())->toBeTrue()
        ->and(Session::query()->count())->toBe(2);
});

test('the sessions table really has no foreign key to cascade from', function () {
    /*
     * The reason `destroy()` deletes session rows by hand. If a later migration ever adds the
     * constraint, this fails and the explicit delete becomes redundant rather than silently
     * duplicated. Asserted against the live schema rather than the migration source.
     */
    $foreignKeys = DB::getSchemaBuilder()->getForeignKeys('sessions');

    expect($foreignKeys)->toBe([]);
});

test('deleting an account cascades to its passkeys without help from the controller', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $passkey = PasskeyFactory::new()->for($member)->create();
    $keptPasskey = PasskeyFactory::new()->for($admin)->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', ['user' => $member]))
        ->assertRedirect(route('admin.users.index'));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse()
        ->and(Passkey::query()->whereKey($keptPasskey->getKey())->exists())->toBeTrue();
});

test('the passkeys table cascades on delete, which is why the controller does not', function () {
    $cascading = collect(DB::getSchemaBuilder()->getForeignKeys('passkeys'))
        ->firstWhere(fn (array $key): bool => $key['columns'] === ['user_id']);

    expect($cascading)->not->toBeNull()
        ->and($cascading['foreign_table'])->toBe('users')
        ->and(strtolower((string) $cascading['on_delete']))->toBe('cascade');
});

test('deleting an account leaves invitations it issued standing with nobody attached', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    [$invitation] = invitationWithToken(['invited_by_id' => $other->id]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', ['user' => $other]))
        ->assertRedirect(route('admin.users.index'));

    expect($invitation->fresh()?->invited_by_id)->toBeNull();
});
