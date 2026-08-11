<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The games administration screen
|--------------------------------------------------------------------------
|
| Three things are pinned here that a later change is likely to undo.
|
| The short name is uppercased in `prepareForValidation()`, which is *before* the rules run, and
| the character class is `[A-Z0-9-]` with no lowercase in it. Both halves are asserted together
| because either one alone is wrong: uppercasing after the check would accept `run 1`, and
| allowing `a-z` in the class would make the uppercasing invisible. `run-1` → `RUN-1` stored, and
| `run 1` rejected, are the two ends of that one rule.
|
| Seat counts are presented as active-of-total from `withCount(['seats', 'activeSeats'])`, using
| the dedicated relation rather than a closure alias so `active_seats_count` is a property
| PHPStan can see at level 8.
|
| Deleting a game is the one operation in the application that destroys seats — `game_seats`
| cascades — which is why the confirmation names how many go with it, and why there is no seat
| destroy endpoint anywhere.
|
*/

/**
 * Every route on the screen, as [method, url factory] pairs.
 *
 * @return array<string, array{0: string, 1: Closure(Game): string}>
 */
function gameAdminRoutes(): array
{
    return [
        'index' => ['get', fn (): string => route('admin.games.index')],
        'store' => ['post', fn (): string => route('admin.games.store')],
        'show' => ['get', fn (Game $game): string => route('admin.games.show', ['game' => $game])],
        'update' => ['put', fn (Game $game): string => route('admin.games.update', ['game' => $game])],
        'destroy' => ['delete', fn (Game $game): string => route('admin.games.destroy', ['game' => $game])],
    ];
}

/**
 * A valid payload for creating or updating a game.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gamePayload(array $overrides = []): array
{
    return [
        'name' => 'The Long Retreat',
        'short_name' => 'RETREAT',
        'status' => GameStatus::Active->value,
        ...$overrides,
    ];
}

test('a guest is redirected to login from every game route', function () {
    $game = Game::factory()->create();

    foreach (gameAdminRoutes() as [$method, $url]) {
        $this->{$method}($url($game), gamePayload())->assertRedirect(route('login'));
    }

    expect(Game::query()->count())->toBe(1);
});

test('a member is forbidden from every game route', function () {
    $member = User::factory()->create();
    $game = Game::factory()->create(['name' => 'Untouched']);

    foreach (gameAdminRoutes() as [$method, $url]) {
        $this->actingAs($member)->{$method}($url($game), gamePayload())->assertForbidden();
    }

    expect(Game::query()->count())->toBe(1);
    expect($game->fresh()?->name)->toBe('Untouched');
});

test('an unverified administrator is sent to email verification from every game route', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $game = Game::factory()->create();

    foreach (gameAdminRoutes() as [$method, $url]) {
        $this->actingAs($admin)
            ->{$method}($url($game), gamePayload())
            ->assertRedirect(route('verification.notice'));
    }
});

test('the list shows every game with its short name, status, seat counts and created date', function () {
    $admin = User::factory()->admin()->create();

    $alpha = Game::factory()->active()->create(['name' => 'Alpha Run', 'short_name' => 'ALPHA']);
    $beta = Game::factory()->archived()->create(['name' => 'Beta Run', 'short_name' => 'BETA']);

    GameSeat::factory()->count(2)->for($alpha)->create();
    GameSeat::factory()->retired()->for($alpha)->create();

    $this->actingAs($admin)
        ->get(route('admin.games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/games/Index')
            ->has('games', 2)
            /* Ordered by name: Alpha, Beta. */
            ->where('games.0.name', 'Alpha Run')
            ->where('games.0.short_name', 'ALPHA')
            ->where('games.0.status', 'active')
            ->where('games.0.status_label', 'Active')
            ->where('games.0.seats_count', 3)
            ->where('games.0.active_seats_count', 2)
            ->where('games.0.created_at', $alpha->created_at?->toDayDateTimeString())
            ->where('games.1.name', 'Beta Run')
            ->where('games.1.status', 'archived')
            ->where('games.1.status_label', 'Archived')
            ->where('games.1.seats_count', 0)
            ->where('games.1.active_seats_count', 0)
            /* Archived games stay in the inventory; the count is what says how many are live. */
            ->where('unarchivedCount', 1)
            ->where('games.1.id', $beta->id),
        );
});

test('the seat counts distinguish active from total', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'A Counted Game']);

    GameSeat::factory()->count(4)->for($game)->create();
    GameSeat::factory()->count(3)->retired()->for($game)->create();

    /* Seats at another game must not be counted against this one. */
    $other = Game::factory()->create(['name' => 'Z Other Game']);
    GameSeat::factory()->count(5)->for($other)->create();

    $this->actingAs($admin)
        ->get(route('admin.games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            /* Ordered by name, so the counted game is first and the other one last. */
            ->where('games.0.name', 'A Counted Game')
            ->where('games.0.seats_count', 7)
            ->where('games.0.active_seats_count', 4)
            ->where('games.1.name', 'Z Other Game')
            ->where('games.1.seats_count', 5)
            ->where('games.1.active_seats_count', 5),
        );
});

test('an administrator can create a game and it starts in setup with no seats', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.games.store'), [
        'name' => 'The Long Retreat',
        'short_name' => 'RETREAT',
        /* A status posted alongside is not accepted: a new game is always in setup. */
        'status' => GameStatus::Completed->value,
    ]);

    $game = Game::query()->sole();

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'The Long Retreat was created.',
    ]);

    expect($game->name)->toBe('The Long Retreat');
    expect($game->short_name)->toBe('RETREAT');
    expect($game->status)->toBe(GameStatus::Setup);
    expect($game->seats()->count())->toBe(0);
});

test('a short name is uppercased before it is stored', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Run One', 'short_name' => 'run-1'])
        ->assertSessionHasNoErrors();

    expect(Game::query()->sole()->short_name)->toBe('RUN-1');
});

test('a short name containing a space is rejected with the pointed message', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Run One', 'short_name' => 'run 1']);

    $response->assertSessionHasErrors([
        'short_name' => 'The short name may only contain letters, numbers and hyphens.',
    ]);

    expect(Game::query()->count())->toBe(0);
});

test('the uppercasing happens before the character check, not after it', function () {
    /*
     * The pair that proves the ordering. `run-1` only passes because it was folded *first*, and
     * `run 1` only fails because the pattern is checked *after* the folding and rejects the space.
     * Move the uppercasing after the rules and the first of these fails; put `a-z` in the class and
     * nothing proves the folding happened at all.
     */
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Lower Case', 'short_name' => 'run-1'])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'With Space', 'short_name' => 'run 1'])
        ->assertSessionHasErrors('short_name');

    expect(Game::query()->pluck('short_name')->all())->toBe(['RUN-1']);
});

test('a short name is rejected when it is too long or contains punctuation', function (string $shortName) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Some Game', 'short_name' => $shortName])
        ->assertSessionHasErrors('short_name');

    expect(Game::query()->count())->toBe(0);
})->with([
    'too long' => 'SEVENTEEN-CHARS-X',
    'underscore' => 'RUN_1',
    'full stop' => 'RUN.1',
    'slash' => 'RUN/1',
    'accented letter' => 'RÜN',
    'empty' => '',
]);

test('the short name length limit matches the column width', function () {
    expect(Game::SHORT_NAME_MAX_LENGTH)->toBe(16);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Sixteen', 'short_name' => str_repeat('A', 16)])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Seventeen', 'short_name' => str_repeat('A', 17)])
        ->assertSessionHasErrors('short_name');
});

test('a name and a short name must each be unique across games', function () {
    $admin = User::factory()->admin()->create();
    Game::factory()->create(['name' => 'Taken Name', 'short_name' => 'TAKEN']);

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Taken Name', 'short_name' => 'FREE'])
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Free Name', 'short_name' => 'TAKEN'])
        ->assertSessionHasErrors('short_name');

    /* Uniqueness applies to the folded value, so a lowercase spelling of a taken short name collides. */
    $this->actingAs($admin)
        ->post(route('admin.games.store'), ['name' => 'Free Name', 'short_name' => 'taken'])
        ->assertSessionHasErrors('short_name');

    expect(Game::query()->count())->toBe(1);
});

test('an administrator can see one game with its roster and the accounts it can still seat', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);
    $game = Game::factory()->active()->create(['name' => 'Alpha Run', 'short_name' => 'ALPHA']);

    $seated = User::factory()->create(['name' => 'Grace Hopper']);
    GameSeat::factory()->for($game)->for($seated)->gamemaster()->create();

    $free = User::factory()->create(['name' => 'Zoe Free']);

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/games/Show')
            ->where('game.id', $game->id)
            ->where('game.name', 'Alpha Run')
            ->where('game.short_name', 'ALPHA')
            ->where('game.status', 'active')
            ->where('game.seats_count', 1)
            ->where('game.active_seats_count', 1)
            ->has('seats', 1)
            ->where('seats.0.user_name', 'Grace Hopper')
            ->where('seats.0.role', 'gamemaster')
            ->where('seats.0.role_label', 'Gamemaster')
            ->where('seats.0.is_active', true)
            ->has('assignableAccounts', 2)
            ->where('assignableAccounts.0.name', 'Ada Lovelace')
            ->where('assignableAccounts.1.name', 'Zoe Free')
            ->where('assignableAccounts.0.id', $admin->id)
            ->where('assignableAccounts.1.id', $free->id)
            ->has('roles', 2)
            ->has('statuses', 5),
        );
});

test('the roster lists retired seats alongside active ones, active first', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $retired = User::factory()->create(['name' => 'Aaron Retired']);
    $active = User::factory()->create(['name' => 'Zena Active']);

    GameSeat::factory()->for($game)->for($retired)->retired()->create();
    GameSeat::factory()->for($game)->for($active)->create();

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('seats', 2)
            /* Active first despite sorting later alphabetically, so the live roster reads as one block. */
            ->where('seats.0.user_name', 'Zena Active')
            ->where('seats.0.is_active', true)
            ->where('seats.1.user_name', 'Aaron Retired')
            ->where('seats.1.is_active', false)
            ->where('game.seats_count', 2)
            ->where('game.active_seats_count', 1),
        );
});

test('an archived game is still reachable by its own url', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->archived()->create();

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('game.status', 'archived'));
});

test('an administrator can change a game name, short name and status', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Before', 'short_name' => 'BEFORE']);

    $response = $this->actingAs($admin)->put(route('admin.games.update', ['game' => $game]), [
        'name' => 'After',
        'short_name' => 'after-2',
        'status' => GameStatus::Paused->value,
    ]);

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'After was updated.']);

    $game->refresh();

    expect($game->name)->toBe('After');
    /* The same folding as creation: uppercased before the character check. */
    expect($game->short_name)->toBe('AFTER-2');
    expect($game->status)->toBe(GameStatus::Paused);
});

test('a game can be saved without changing its own name or short name', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Same Name', 'short_name' => 'SAME']);

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), [
            'name' => 'Same Name',
            'short_name' => 'SAME',
            'status' => GameStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('an update is rejected when it collides with another game or carries an unknown status', function () {
    $admin = User::factory()->admin()->create();
    Game::factory()->create(['name' => 'Other Game', 'short_name' => 'OTHER']);
    $game = Game::factory()->create(['name' => 'Mine', 'short_name' => 'MINE']);

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload(['name' => 'Other Game']))
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload(['short_name' => 'OTHER']))
        ->assertSessionHasErrors('short_name');

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload(['status' => 'abandoned']))
        ->assertSessionHasErrors('status');

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload(['short_name' => 'run 1']))
        ->assertSessionHasErrors([
            'short_name' => 'The short name may only contain letters, numbers and hyphens.',
        ]);

    expect($game->fresh()?->name)->toBe('Mine');
    expect($game->fresh()?->short_name)->toBe('MINE');
});

test('deleting a game deletes its seats through the cascade', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Doomed Run']);
    $survivor = Game::factory()->create();

    $doomedSeats = GameSeat::factory()->count(2)->for($game)->create();
    $retiredSeat = GameSeat::factory()->retired()->for($game)->create();
    $survivingSeat = GameSeat::factory()->for($survivor)->create();

    $response = $this->actingAs($admin)->delete(route('admin.games.destroy', ['game' => $game]));

    $response->assertRedirect(route('admin.games.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Doomed Run was deleted.']);

    expect(Game::query()->whereKey($game->getKey())->exists())->toBeFalse();

    foreach ([...$doomedSeats->all(), $retiredSeat] as $seat) {
        expect(GameSeat::query()->whereKey($seat->getKey())->exists())->toBeFalse();
    }

    expect(GameSeat::query()->whereKey($survivingSeat->getKey())->exists())->toBeTrue();
});

test('deleting an account deletes its seats through the cascade too', function () {
    $user = User::factory()->create();
    $seat = GameSeat::factory()->for($user)->create();

    $user->delete();

    expect(GameSeat::query()->whereKey($seat->getKey())->exists())->toBeFalse();
});

test('both game seat foreign keys really cascade in the schema', function () {
    /*
     * Asserted against the schema rather than only through behaviour, so that if a constraint is ever
     * changed to `nullOnDelete` or dropped, the failure names the cause instead of surfacing as a
     * confusing leftover row somewhere else.
     */
    $onDelete = collect(Schema::getForeignKeys('game_seats'))
        ->mapWithKeys(fn (array $key): array => [$key['columns'][0] => mb_strtolower((string) $key['on_delete'])]);

    expect($onDelete->get('game_id'))->toBe('cascade');
    expect($onDelete->get('user_id'))->toBe('cascade');
});

test('the unique index on game seats spans game and user and ignores is_active', function () {
    /*
     * The index is what makes "retired seats still count" true at the database level rather than only
     * in a validation rule. If `is_active` were ever added to the key, a retired account could get a
     * second row and the reactivation contract would quietly break.
     */
    $index = collect(Schema::getIndexes('game_seats'))
        ->first(fn (array $index): bool => $index['unique'] === true && in_array('game_id', $index['columns'], true));

    expect($index)->not->toBeNull();
    expect($index['columns'])->toBe(['game_id', 'user_id']);
});

test('the games table has no soft deletes to hide a game behind', function () {
    expect(Schema::hasColumn('games', 'deleted_at'))->toBeFalse();
});
