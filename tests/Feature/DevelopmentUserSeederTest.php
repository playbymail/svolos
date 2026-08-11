<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * Make `app()->environment()` genuinely report the given environment.
 *
 * `config(['app.env' => ...])` is not enough: `Application::environment()` reads the container's
 * `env` binding, which `LoadConfiguration` writes once during bootstrap and never consults the
 * config value for again. `detectEnvironment()` is the framework's own API for writing that
 * binding, so the seeder's guard ends up reading a real environment rather than a mock of the call
 * it happens to make today.
 *
 * The expectations are part of the helper on purpose. If a future framework version changed how
 * that binding is written, every "creates nothing in production" test below would otherwise start
 * passing for the wrong reason — still running in `testing`, where the guard is *supposed* to allow
 * seeding, with something unrelated accounting for the empty table.
 */
function pretendEnvironmentIs(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);

    expect(app()->environment())->toBe($environment)
        ->and(app()->environment([$environment]))->toBeTrue();
}

/**
 * Run a seeder the way the console does, past the "Application In Production!" prompt.
 *
 * `--force` is deliberate. Without it `ConfirmableTrait` cancels `db:seed` in production before the
 * seeder is ever constructed, so a test asserting an empty table afterwards would pass just as
 * happily with the environment guard deleted and would prove nothing about it. Forcing the command
 * removes that unrelated barrier and leaves `DevelopmentUserSeeder::run()` as the only thing that
 * can decline; asserting the exit code pins that the command really did proceed, because a
 * cancelled `db:seed` exits non-zero.
 */
function seedForcing(string $seeder): void
{
    $exitCode = Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);

    expect($exitCode)->toBe(0);
}

test('the seeder creates six verified members in the local environment', function () {
    pretendEnvironmentIs('local');

    seedForcing(DevelopmentUserSeeder::class);

    expect(DevelopmentUserSeeder::ACCOUNTS)->toBe(6);

    $this->assertDatabaseCount('users', 6);

    foreach (range(1, 6) as $index) {
        $user = User::query()->where('email', DevelopmentUserSeeder::email($index))->firstOrFail();

        expect($user->email)->toBe("user{$index}@example.com")
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->role)->toBe(UserRole::Member)
            ->and($user->name)->toBe("Member {$index}")
            ->and(Hash::check(DevelopmentUserSeeder::password($index), $user->password))->toBeTrue();
    }
});

test('every account has its own address and its own password', function () {
    $indexes = collect(range(1, DevelopmentUserSeeder::ACCOUNTS));

    $emails = $indexes->map(fn (int $index): string => DevelopmentUserSeeder::email($index));
    $passwords = $indexes->map(fn (int $index): string => DevelopmentUserSeeder::password($index));

    expect($emails->unique())->toHaveCount(DevelopmentUserSeeder::ACCOUNTS)
        ->and($passwords->unique())->toHaveCount(DevelopmentUserSeeder::ACCOUNTS);
});

test('the helpers reject an index no account was seeded for', function (int $index) {
    expect(fn (): string => DevelopmentUserSeeder::email($index))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): string => DevelopmentUserSeeder::password($index))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, 7, 100]);

test('a member can sign in with the credentials the helpers report', function () {
    $this->seed(DevelopmentUserSeeder::class);

    $response = $this->post(route('login.store'), [
        'email' => DevelopmentUserSeeder::email(1),
        'password' => DevelopmentUserSeeder::password(1),
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs(
        User::query()->where('email', DevelopmentUserSeeder::email(1))->firstOrFail()
    );
});

test('a plain db:seed of the whole manifest includes the development accounts', function () {
    $this->artisan('db:seed')->assertSuccessful();

    $this->assertDatabaseCount('users', DevelopmentUserSeeder::ACCOUNTS);

    foreach (range(1, DevelopmentUserSeeder::ACCOUNTS) as $index) {
        $this->assertDatabaseHas('users', ['email' => DevelopmentUserSeeder::email($index)]);
    }
});

test('the seeder creates nothing outside local and testing', function (string $environment) {
    pretendEnvironmentIs($environment);

    expect(app()->environment(['local', 'testing']))->toBeFalse();

    seedForcing(DevelopmentUserSeeder::class);

    $this->assertDatabaseEmpty('users');
})->with(['production', 'staging', 'demo']);

test('the whole manifest creates no known-credential account outside local and testing', function () {
    pretendEnvironmentIs('production');

    expect(app()->environment(['local', 'testing']))->toBeFalse();

    seedForcing(DatabaseSeeder::class);

    $this->assertDatabaseEmpty('users');
});

test('re-running the seeder leaves a renamed and promoted account exactly as it was', function () {
    $this->seed(DevelopmentUserSeeder::class);

    $promoted = User::query()->where('email', DevelopmentUserSeeder::email(3))->firstOrFail();
    $promoted->name = 'Renamed And Promoted';
    $promoted->role = UserRole::Admin;
    $promoted->save();

    $originalId = $promoted->id;

    $this->seed(DevelopmentUserSeeder::class);

    $this->assertDatabaseCount('users', DevelopmentUserSeeder::ACCOUNTS);

    expect(User::query()->where('email', DevelopmentUserSeeder::email(3))->count())->toBe(1);

    $promoted->refresh();

    expect($promoted->id)->toBe($originalId)
        ->and($promoted->name)->toBe('Renamed And Promoted')
        ->and($promoted->role)->toBe(UserRole::Admin);
});

test('re-running the seeder fills in only the accounts that are missing', function () {
    $this->seed(DevelopmentUserSeeder::class);

    $originalIds = User::query()->orderBy('id')->pluck('id', 'email')->all();

    User::query()->where('email', DevelopmentUserSeeder::email(2))->delete();

    $this->assertDatabaseMissing('users', ['email' => DevelopmentUserSeeder::email(2)]);

    $this->seed(DevelopmentUserSeeder::class);

    $this->assertDatabaseCount('users', DevelopmentUserSeeder::ACCOUNTS);
    $this->assertDatabaseHas('users', ['email' => DevelopmentUserSeeder::email(2)]);

    foreach ($originalIds as $email => $id) {
        if ($email === DevelopmentUserSeeder::email(2)) {
            continue;
        }

        expect(User::query()->where('email', $email)->firstOrFail()->id)->toBe($id);
    }
});
