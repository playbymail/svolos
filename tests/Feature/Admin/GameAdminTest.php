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
        'seed.update' => ['put', fn (Game $game): string => route('admin.games.seed.update', ['game' => $game])],
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
        /* Paused, not active: a game cannot become active until its world has been generated, and a
         * payload used as "one that would otherwise succeed" has to keep being exactly that. */
        'status' => GameStatus::Paused->value,
        /* Carried for the seed route in the sweeps above; the other routes validate no such field. */
        'seed' => 1234,
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
            'status' => GameStatus::Paused->value,
        ])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->status)->toBe(GameStatus::Paused);
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

test('a new game is created with a seed of its own, drawn inside the engine range', function () {
    /*
     * Nobody chooses the seed at creation — `GameStoreRequest` accepts no such field, exactly as it
     * accepts no status — so this is asserting that `Game::booted()` assigned one rather than that a
     * posted value survived. The range is the engine's: `Game::SEED_MAX` is the width of PHP's
     * Mersenne Twister seed, so every value in it names a different game.
     */
    $admin = User::factory()->admin()->create();

    foreach (['ONE', 'TWO', 'THREE'] as $shortName) {
        $this->actingAs($admin)
            ->post(route('admin.games.store'), [
                'name' => "Run {$shortName}",
                'short_name' => $shortName,
                /* Posted and ignored, like a posted status: a seed is assigned, never chosen here. */
                'seed' => 7,
            ])
            ->assertSessionHasNoErrors();
    }

    $seeds = Game::query()->pluck('seed');

    expect($seeds)->toHaveCount(3);

    foreach ($seeds as $seed) {
        expect($seed)->toBeInt()
            ->toBeGreaterThanOrEqual(Game::SEED_MIN)
            ->toBeLessThanOrEqual(Game::SEED_MAX);
    }

    /*
     * Random, not fixed. Three draws from 4,294,967,296 values collide about once in a billion runs,
     * which is the price of catching a hook that hands every game the same number — the failure mode
     * a constant default or a single-statement backfill would produce.
     */
    expect($seeds->unique())->toHaveCount(3);
    expect($seeds->contains(7))->toBeFalse();
});

test('an administrator can change the seed while the game is in setup', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Alpha Run', 'seed' => 111]);

    $response = $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => 4242]);

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Alpha Run is now seeded with 4242.',
    ]);

    expect($game->fresh()?->seed)->toBe(4242);
});

test('the ends of the range are both accepted', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    foreach ([Game::SEED_MIN, Game::SEED_MAX] as $seed) {
        $this->actingAs($admin)
            ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => $seed])
            ->assertSessionHasNoErrors();

        expect($game->fresh()?->seed)->toBe($seed);
    }
});

test('the seed can no longer be changed once the game has left setup', function (string $state) {
    /*
     * The rule the seed exists to have. A seed is the number a run was drawn from, so re-seeding a
     * game that is being played would describe a run that never happened — its turn reports would no
     * longer follow from its seed.
     *
     * This is a **validation** failure rather than a 403: the value is fine and the requester may post
     * it, the game is simply in the wrong state, and the same administrator may post the same number
     * the moment it goes back to setup. The gamemaster area's four refusals are 403s for the opposite
     * reason — see `.ai/rules/gamemaster.md`.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->{$state}()->create(['seed' => 111]);

    $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => 222])
        ->assertSessionHasErrors([
            'seed' => 'The seed can only be changed while the game is in setup.',
        ]);

    expect($game->fresh()?->seed)->toBe(111);
})->with(['active', 'paused', 'completed', 'archived']);

test('a game put back into setup can be re-seeded again', function () {
    /*
     * The other half of the rule above: the refusal is about the game's state, so it lifts when the
     * state does. A 403 would have been the wrong shape for something that comes back.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->active()->create(['seed' => 111]);

    $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => 222])
        ->assertSessionHasErrors('seed');

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload([
            'name' => $game->name,
            'short_name' => $game->short_name,
            'status' => GameStatus::Setup->value,
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => 222])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->seed)->toBe(222);
});

test('a seed outside the range or not a whole number is rejected', function (mixed $seed) {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['seed' => 111]);

    $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), ['seed' => $seed])
        ->assertSessionHasErrors('seed');

    expect($game->fresh()?->seed)->toBe(111);
})->with([
    'negative' => -1,
    'past the 32-bit ceiling' => 4294967296,
    'fractional' => 1.5,
    'not a number' => 'lucky',
    'empty' => '',
]);

test('the range the seed is validated against is the engine range', function () {
    /*
     * Named rather than assumed, because the bound is not arbitrary: `Random\Engine\Mt19937` takes a
     * 32-bit unsigned seed, so this is exactly the set of values that produce distinct sequences. The
     * migration's column is `unsignedInteger` for the same reason.
     */
    expect(Game::SEED_MIN)->toBe(0);
    expect(Game::SEED_MAX)->toBe(4294967295);
});

test('the metadata form cannot write a seed, and the seed form cannot write anything else', function () {
    /*
     * `seed` is out of `Game`'s `#[Fillable]`, so the two seed endpoints are the only places it can
     * change — a posted seed on the metadata form is dropped rather than written, exactly as
     * `is_active` cannot ride along on a seat's role change. The mirror assertion is that the seed
     * endpoint writes only the seed: its request validates one field, so a name posted with it is not
     * in `validated()` at all.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Before', 'short_name' => 'BEFORE', 'seed' => 111]);

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), gamePayload([
            'name' => 'After',
            'short_name' => 'AFTER',
            'status' => GameStatus::Setup->value,
            'seed' => 999,
        ]))
        ->assertSessionHasNoErrors();

    $fresh = $game->fresh();

    expect($fresh?->name)->toBe('After');
    expect($fresh?->seed)->toBe(111);

    $this->actingAs($admin)
        ->put(route('admin.games.seed.update', ['game' => $game]), [
            'seed' => 222,
            'name' => 'Renamed By The Seed Form',
            'short_name' => 'STOLEN',
            'status' => GameStatus::Archived->value,
        ])
        ->assertSessionHasNoErrors();

    $fresh = $game->fresh();

    expect($fresh?->seed)->toBe(222);
    expect($fresh?->name)->toBe('After');
    expect($fresh?->short_name)->toBe('AFTER');
    expect($fresh?->status)->toBe(GameStatus::Setup);
});

test('the list and the game screen both show the seed and whether it can still be changed', function () {
    $admin = User::factory()->admin()->create();

    $setup = Game::factory()->create(['name' => 'Alpha Run', 'seed' => 4242]);
    $started = Game::factory()->active()->create(['name' => 'Beta Run', 'seed' => 99]);

    $this->actingAs($admin)
        ->get(route('admin.games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('games.0.seed', 4242)
            ->where('games.0.can_change_seed', true)
            ->where('games.1.seed', 99)
            /* Beta Run is active, so its seed is fixed and the screen renders it as text. */
            ->where('games.1.can_change_seed', false)
            ->etc(),
        );

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $started]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.seed', 99)
            ->where('game.can_change_seed', false)
            /*
             * The screen renders this sentence rather than composing its own, because the other reason
             * a seed locks — a game still in setup whose world has been generated — needs a completely
             * different one. See `tests/Feature/Gamemaster/GenerationTest.php`.
             */
            ->where('game.seed_lock_reason', 'The game has left setup, so its seed is fixed. Everything it has generated was drawn from this number, and changing it now would describe a run that never happened.')
            ->etc(),
        );

    /* A game nobody has generated anything for yet is open, and says nothing about why. */
    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $setup]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.can_change_seed', true)
            ->where('game.seed_lock_reason', null)
            ->etc(),
        );
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

test('the seed column is not nullable, and the games table kept its indexes through the change', function () {
    /*
     * Adding the seed takes three steps — add nullable, backfill, close to nulls — and the last of
     * them rebuilds the table on SQLite. Both halves are asserted because both are easy to lose
     * quietly: a nullable seed would let a game be played with no recorded randomness, and a rebuild
     * that dropped the unique indexes would leave two games able to share a short name, which is the
     * identifier that goes into turn reports and generated file names.
     */
    $seed = collect(Schema::getColumns('games'))->firstWhere('name', 'seed');

    expect($seed)->not->toBeNull();
    expect($seed['nullable'])->toBeFalse();

    $indexed = collect(Schema::getIndexes('games'))
        ->filter(fn (array $index): bool => $index['unique'] === true)
        ->pluck('columns')
        ->flatten();

    expect($indexed)->toContain('name');
    expect($indexed)->toContain('short_name');
});

test('the games table has no soft deletes to hide a game behind', function () {
    expect(Schema::hasColumn('games', 'deleted_at'))->toBeFalse();
});
