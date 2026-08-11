<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\UserRole;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The user role and the mass-assignment boundary
|--------------------------------------------------------------------------
|
| `role` is the difference between a member and an administrator, so it must not be
| reachable from request input. Two independent layers stop that, and both are asserted
| here separately on purpose:
|
| 1. The model layer — `role` is absent from `#[Fillable]`, so `fill()` and `create()`
|    discard it wherever the data came from. The `fill()` and `getFillable()` tests below
|    fail the moment `role` is added to that list.
| 2. The request layer — `ProfileUpdateRequest::rules()` only whitelists `name` and
|    `email`, so `$request->validated()` never carries a `role` key to `fill()` in the
|    first place.
|
| The HTTP test near the bottom is the acceptance criterion. Its observable outcome is
| protected by layer 2 as well, so posting `role=admin` would still fail to escalate even
| with `role` fillable — which is why it asserts both layers explicitly rather than relying
| on the outcome, and why layer 1 also has dedicated tests of its own.
|
*/

test('the role enum labels every case for the interface', function () {
    expect(UserRole::Admin->value)->toBe('admin');
    expect(UserRole::Member->value)->toBe('member');
    expect(UserRole::Admin->label())->toBe('Administrator');
    expect(UserRole::Member->label())->toBe('Member');
    expect(UserRole::cases())->toHaveCount(2);
});

test('an unsaved user already reads back as a member', function () {
    expect((new User)->role)->toBe(UserRole::Member);
});

test('a created user defaults to the member role', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::Member);
    expect($user->isAdmin())->toBeFalse();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'member']);
});

test('a user created without the factory still defaults to the member role', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    expect($user->fresh()?->role)->toBe(UserRole::Member);
});

test('the admin factory state creates an administrator', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->isAdmin())->toBeTrue();
    $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
});

test('the role column is cast to the enum when read back from the database', function () {
    $admin = User::factory()->admin()->create();

    expect(User::query()->findOrFail($admin->id)->role)->toBe(UserRole::Admin);
});

test('the role is shared with the frontend so the sidebar can hide the admin link', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('auth.user.role', 'member'),
    );

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('auth.user.role', 'admin'),
    );
});

test('the role attribute is absent from the fillable attributes', function () {
    expect((new User)->getFillable())
        ->toBe(['name', 'email', 'password'])
        ->not->toContain('role');
});

test('the role attribute cannot be filled', function () {
    $user = User::factory()->create();

    $user->fill(['name' => 'Filled Name', 'role' => UserRole::Admin->value]);
    $user->save();

    expect($user->fresh()?->name)->toBe('Filled Name');
    expect($user->fresh()?->role)->toBe(UserRole::Member);
    expect($user->fresh()?->isAdmin())->toBeFalse();
});

test('the role attribute cannot be mass assigned on creation', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'role' => UserRole::Admin->value,
    ]);

    expect($user->fresh()?->role)->toBe(UserRole::Member);
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'role' => 'member']);
});

test('the profile update request whitelists only the name and email fields', function () {
    expect(array_keys((new ProfileUpdateRequest)->rules()))->toBe(['name', 'email']);
});

test('posting a role to the profile update endpoint does not escalate the account', function () {
    /*
     * Both layers are pinned here rather than only the observable outcome. The outcome alone
     * cannot tell them apart: with `role` fillable, this endpoint still would not escalate,
     * because `ProfileUpdateRequest` strips the field before `fill()` ever sees it. Asserting the
     * fillable list as well makes this test — the acceptance criterion — fail for either mutation
     * instead of quietly passing on the strength of the other layer.
     */
    expect((new User)->getFillable())->not->toContain('role');
    expect(array_keys((new ProfileUpdateRequest)->rules()))->not->toContain('role');

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
            'role' => UserRole::Admin->value,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->role)->toBe(UserRole::Member);
    expect($user->isAdmin())->toBeFalse();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'member']);

    $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
});

test('a member cannot escalate through the account creation action either', function () {
    app(CreateNewUser::class)->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => UserRole::Admin->value,
    ]);

    expect(User::query()->where('email', 'test@example.com')->firstOrFail()->role)
        ->toBe(UserRole::Member);
});
