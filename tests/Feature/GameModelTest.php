<?php

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Http\Controllers\Admin\GameController;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/*
|--------------------------------------------------------------------------
| The Game and GameSeat models
|--------------------------------------------------------------------------
|
| These live in the Feature suite rather than Unit because every one of them touches the database:
| `tests/Pest.php` binds `Tests\TestCase` and `RefreshDatabase` to Feature only.
|
| The two things worth pinning below the HTTP layer are the `unarchived` scope — the only piece of
| behaviour any status carries — and `activeSeats()` being a real relation, which is what
| `withCount(['seats', 'activeSeats'])` names and what backs the `@property-read
| int|null $active_seats_count` on the model. Larastan will not catch a closure alias in its place
| (`checkModelProperties` is off, so an undeclared model property reads as `mixed`), so the shape is
| held here by an assertion and by the rules file rather than by the analyser.
|
*/

test('the unarchived scope excludes archived games and keeps every other status', function () {
    $archived = Game::factory()->archived()->create();

    $others = [
        GameStatus::Setup->value => Game::factory()->create(),
        GameStatus::Active->value => Game::factory()->active()->create(),
        GameStatus::Paused->value => Game::factory()->paused()->create(),
        GameStatus::Completed->value => Game::factory()->completed()->create(),
    ];

    $unarchived = Game::query()->unarchived()->pluck('id');

    expect($unarchived)->toHaveCount(4);
    expect($unarchived->contains($archived->id))->toBeFalse();

    foreach ($others as $status => $game) {
        expect($unarchived->contains($game->id))->toBeTrue("a {$status} game was excluded");
    }

    /* The scope filters rather than removing rows: the archived game is still there to be restored. */
    expect(Game::query()->count())->toBe(5);
});

test('the unarchived scope composes with other constraints', function () {
    Game::factory()->archived()->create(['name' => 'Archived Alpha']);
    $active = Game::factory()->active()->create(['name' => 'Active Alpha']);

    expect(Game::query()->unarchived()->where('name', 'like', '%Alpha%')->pluck('id')->all())
        ->toBe([$active->id]);
});

test('activeSeats is a relation that excludes retired seats while seats keeps them', function () {
    $game = Game::factory()->create();

    $active = GameSeat::factory()->count(3)->for($game)->create();
    $retired = GameSeat::factory()->count(2)->retired()->for($game)->create();

    expect($game->seats()->count())->toBe(5);
    expect($game->activeSeats()->count())->toBe(3);
    expect($game->activeSeats()->pluck('id')->sort()->values()->all())
        ->toBe($active->pluck('id')->sort()->values()->all());

    foreach ($retired as $seat) {
        expect($game->activeSeats()->whereKey($seat->getKey())->exists())->toBeFalse();
    }
});

test('withCount uses the activeSeats relation to produce active_seats_count', function () {
    /*
     * The property is declared on `Game` as `@property-read int|null $active_seats_count` and is filled by
     * naming the relation. Asserting the value proves the relation and the annotation agree.
     */
    $game = Game::factory()->create();

    GameSeat::factory()->count(2)->for($game)->create();
    GameSeat::factory()->retired()->for($game)->create();

    $loaded = Game::query()->withCount(['seats', 'activeSeats'])->findOrFail($game->id);

    expect($loaded->seats_count)->toBe(3);
    expect($loaded->active_seats_count)->toBe(2);

    $reloaded = $game->loadCount(['seats', 'activeSeats']);

    expect($reloaded->seats_count)->toBe(3);
    expect($reloaded->active_seats_count)->toBe(2);
});

test('the games screen counts through the relation and never through a closure alias', function () {
    /*
     * This has to be asserted against the source, because a closure alias
     * (`'seats as active_seats_count' => fn ($query) => …`) produces byte-identical output and Larastan
     * does not object either: `checkModelProperties` is off in `phpstan.neon`, so an undeclared model
     * property reads as `mixed` and passes level 8. Nothing but this test stands between the relation and
     * a count named after a string that no longer refers to anything.
     */
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(GameController::class))->getFileName()
    );

    expect($source)->toContain("withCount(['seats', 'activeSeats'])");
    expect($source)->toContain("loadCount(['seats', 'activeSeats'])");
    expect($source)->not->toContain('seats as active_seats_count');
});

test('a game defaults to setup and a seat defaults to an active player before either is saved', function () {
    /*
     * The model defaults repeat the column defaults so an unsaved model does not hit the enum cast with a
     * null. Change one and the other has to change with it.
     */
    expect((new Game)->status)->toBe(GameStatus::Setup);
    expect((new GameSeat)->role)->toBe(GameRole::Player);
    expect((new GameSeat)->is_active)->toBeTrue();

    $game = Game::query()->create(['name' => 'Defaulted', 'short_name' => 'DEF']);

    expect($game->fresh()?->status)->toBe(GameStatus::Setup);
});

test('a seat belongs to its game and its account', function () {
    $game = Game::factory()->create();
    $user = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($user)->create();

    expect($seat->game->is($game))->toBeTrue();
    expect($seat->user->is($user))->toBeTrue();
});

test('the database refuses a second seat row for the same account at the same game', function () {
    /*
     * The unique index, exercised directly rather than through validation, so the guarantee is pinned even
     * if a future write path skips the form request.
     */
    $game = Game::factory()->create();
    $user = User::factory()->create();

    GameSeat::factory()->for($game)->for($user)->retired()->create();

    expect(fn () => GameSeat::factory()->for($game)->for($user)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('game status and game role both label every case', function () {
    foreach (GameStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    }

    foreach (GameRole::cases() as $role) {
        expect($role->label())->toBeString()->not->toBeEmpty();
    }

    expect(GameStatus::Setup->label())->toBe('Setup');
    expect(GameStatus::Archived->label())->toBe('Archived');
    expect(GameRole::Player->label())->toBe('Player');
    expect(GameRole::Gamemaster->label())->toBe('Gamemaster');
});
