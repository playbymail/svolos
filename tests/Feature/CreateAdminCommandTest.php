<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| app:create-admin
|--------------------------------------------------------------------------
|
| The only supported way an account becomes an administrator. Every path through the command
| is covered here: create, promote-confirmed, promote-declined, already-an-administrator,
| and validation failure.
|
| `expectsQuestion` mocks OutputStyle::askQuestion with strictly ordered, ->once()
| expectations, so these tests also assert the *absence* of a question: the promotion tests
| would fail if the command started asking for a password it has no business changing, and
| the already-an-administrator test would fail if the command asked anything at all.
|
| The prompt order for a new account is Email address (unless passed as an argument), Name,
| Password, Confirm password.
|
*/

test('the command creates a new administrator with a verified email address', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password123!')
        ->expectsOutputToContain('was created as an administrator')
        ->assertExitCode(0);

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($admin->name)->toBe('Ada Admin');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->isAdmin())->toBeTrue();
    expect($admin->email_verified_at)->not->toBeNull();
    expect($admin->hasVerifiedEmail())->toBeTrue();
    expect(Hash::check('Password123!', $admin->password))->toBeTrue();
});

test('the command asks for the email address when it is not passed as an argument', function () {
    $this->artisan('app:create-admin')
        ->expectsQuestion('Email address', 'admin@example.com')
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password123!')
        ->assertExitCode(0);

    expect(User::query()->where('email', 'admin@example.com')->firstOrFail()->isAdmin())->toBeTrue();
});

test('the command promotes an existing account once the promotion is confirmed', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->artisan('app:create-admin', ['email' => 'member@example.com'])
        ->expectsConfirmation(
            '[member@example.com] already has an account. Promote it to administrator?',
            'yes',
        )
        ->expectsOutputToContain('was promoted to administrator')
        ->assertExitCode(0);

    expect($member->refresh()->role)->toBe(UserRole::Admin);
    expect($member->isAdmin())->toBeTrue();

    $this->actingAs($member)->get(route('admin.index'))->assertOk();
});

test('the command does not touch the password of an account it promotes', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $originalPassword = $member->password;

    $this->artisan('app:create-admin', ['email' => 'member@example.com'])
        ->expectsConfirmation(
            '[member@example.com] already has an account. Promote it to administrator?',
            'yes',
        )
        ->assertExitCode(0);

    expect($member->refresh()->password)->toBe($originalPassword);
    expect(Hash::check('password', $member->password))->toBeTrue();
});

test('the command makes no changes when the promotion is declined', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->artisan('app:create-admin', ['email' => 'member@example.com'])
        ->expectsConfirmation(
            '[member@example.com] already has an account. Promote it to administrator?',
            'no',
        )
        ->expectsOutputToContain('No changes were made')
        ->assertExitCode(1);

    expect($member->refresh()->role)->toBe(UserRole::Member);
    expect($member->isAdmin())->toBeFalse();
    $this->assertDatabaseHas('users', ['email' => 'member@example.com', 'role' => 'member']);
});

test('the command reports an account that is already an administrator without erroring', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    $originalUpdatedAt = $admin->updated_at;

    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsOutputToContain('is already an administrator')
        ->assertExitCode(0);

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
    expect($admin->updated_at?->toString())->toBe($originalUpdatedAt?->toString());
});

test('running the command twice against the same email address is idempotent', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password123!')
        ->assertExitCode(0)
        ->run();

    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsOutputToContain('is already an administrator')
        ->assertExitCode(0)
        ->run();

    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1);
    expect(User::query()->where('email', 'admin@example.com')->firstOrFail()->isAdmin())->toBeTrue();
});

test('the command rejects a password that fails the password rules', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->expectsOutputToContain('at least 8 characters')
        ->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('the command rejects a password confirmation that does not match', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password456!')
        ->expectsOutputToContain('confirmation does not match')
        ->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('the command rejects an invalid email address', function () {
    $this->artisan('app:create-admin', ['email' => 'not-an-email'])
        ->expectsQuestion('Name', 'Ada Admin')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password123!')
        ->expectsOutputToContain('must be a valid email address')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('the command rejects a missing name', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.com'])
        ->expectsQuestion('Name', '')
        ->expectsQuestion('Password', 'Password123!')
        ->expectsQuestion('Confirm password', 'Password123!')
        ->expectsOutputToContain('name field is required')
        ->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('the command exposes no password option, so a password never lands in shell history', function () {
    $definition = app(Kernel::class)->all()['app:create-admin']->getDefinition();

    expect($definition->hasOption('password'))->toBeFalse();
    expect(array_keys($definition->getOptions()))->not->toContain('password');
    expect(array_keys($definition->getArguments()))->toBe(['email']);
});
