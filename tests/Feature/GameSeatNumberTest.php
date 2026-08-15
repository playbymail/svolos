<?php

use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Empire numbers
|--------------------------------------------------------------------------
|
| A player is empire 3 at their game and stays empire 3. `game_seats.number` is assigned once by
| `GameSeat::booted()` — on the model rather than in either seat controller, so a factory and a seeder
| get one too — and it is out of `#[Fillable]`, which is the strictest of the three exclusions on that
| model: nobody ever posts an empire number.
|
| The rule that is easy to lose, and the reason this is a stored column rather than a seat's position
| among the active player seats, is that the sequence **counts retired seats and never reuses a
| number**. Seats are retired rather than deleted because engine history keeps naming them; a number
| handed out twice would make that history ambiguous. Two tests below pin exactly that, and a
| positional implementation would pass neither.
|
*/

test('numbers start at one and run in creation order within a game', function () {
    $game = Game::factory()->create();

    [$first, $second, $third] = seatPlayers($game, 3);

    expect([$first->number, $second->number, $third->number])->toBe([1, 2, 3]);
});

test('each game numbers its own empires', function () {
    /*
     * Per game, not global. `game_seats.id` is a global auto-increment and would have made the first
     * seat at the second game empire 3.
     */
    $first = Game::factory()->create();
    $second = Game::factory()->create();

    seatPlayers($first, 2);

    [$theirs] = seatPlayers($second, 1);

    expect($theirs->number)->toBe(1);
});

test('a retired seat keeps its number and the next arrival does not get it', function () {
    /*
     * **The test this file exists for.** A positional implementation would renumber the third seat to
     * 2 the moment the second was retired, and the fourth would then also be 3 — two empires with one
     * number, and a history that no longer says which.
     */
    $game = Game::factory()->create();

    [, $second, $third] = seatPlayers($game, 3);

    $second->is_active = false;
    $second->save();

    $fourth = GameSeat::factory()->for($game)->for(User::factory())->create();

    expect($second->fresh())->number->toBe(2);
    expect($third->fresh())->number->toBe(3);
    expect($fourth->number)->toBe(4);
});

test('a reactivated seat comes back as the empire it was', function () {
    $game = Game::factory()->create();

    [, $second] = seatPlayers($game, 2);

    $second->is_active = false;
    $second->save();

    seatPlayers($game, 1);

    $second->is_active = true;
    $second->save();

    expect($second->fresh())->number->toBe(2);
});

test('a gamemaster seat takes a number too', function () {
    /*
     * Numbered like any other seat rather than skipped, because a seat's role can change: a player
     * promoted to gamemaster and back would otherwise have to be given a number at a moment when the
     * sequence had already moved past them.
     */
    $game = Game::factory()->create();

    [$player] = seatPlayers($game, 1);
    $gamemaster = GameSeat::factory()->for($game)->for(User::factory())->gamemaster()->create();

    expect($player->number)->toBe(1);
    expect($gamemaster->number)->toBe(2);
});

test('a number supplied on purpose survives creation', function () {
    /*
     * The `??=` half of the hook, matching `Game::booted()`'s treatment of a supplied seed: a fixture
     * that has to reproduce a recorded roster must not be renumbered on its way to the database.
     */
    $game = Game::factory()->create();

    $seat = GameSeat::factory()->for($game)->for(User::factory())->create(['number' => 7]);

    expect($seat->fresh())->number->toBe(7);

    /* And the sequence carries on from what is actually there, not from how many rows exist. */
    expect(GameSeat::factory()->for($game)->for(User::factory())->create()->number)->toBe(8);
});

test('a number cannot be mass assigned onto an existing seat', function () {
    /*
     * Pinned at the model, because an endpoint test still passes the day it becomes fillable — and
     * `GameProfileUpdateRequest` fills this model from `validated()`.
     */
    $game = Game::factory()->create();

    [$seat] = seatPlayers($game, 1);

    $seat->fill(['empire_name' => 'Renamed', 'number' => 99]);
    $seat->save();

    expect($seat->fresh())
        ->number->toBe(1)
        ->empire_name->toBe('Renamed');
});

test('the database refuses two empires with the same number at one game', function () {
    /*
     * The guard behind the hook rather than inside it. Two seats created at the same instant would
     * compute the same number, and this is what refuses the second — seats are added one at a time by
     * somebody looking at a roster, so serialising every creation would be paying for a race nobody
     * has.
     */
    $game = Game::factory()->create();

    seatPlayers($game, 1);

    expect(fn () => GameSeat::factory()->for($game)->for(User::factory())->create(['number' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the number column is not nullable and is unique within its game', function () {
    /*
     * Asserted against the schema, because the three-step migration that added it — nullable,
     * backfill, close to nulls — rebuilds the table on SQLite, and a rebuild that dropped either
     * property would leave the hook above as the only thing keeping numbers apart. It is not enough:
     * the hook is what computes the next number, and the index is what refuses a collision.
     */
    $number = collect(Schema::getColumns('game_seats'))->firstWhere('name', 'number');

    expect($number)->not->toBeNull();
    expect($number['nullable'])->toBeFalse();

    $index = collect(Schema::getIndexes('game_seats'))
        ->first(fn (array $index): bool => $index['unique'] === true && in_array('number', $index['columns'], true));

    expect($index)->not->toBeNull();
    expect($index['columns'])->toBe(['game_id', 'number']);
});

test('an unnamed empire is called after its game and its seat', function () {
    $game = Game::factory()->create(['short_name' => 'ACME']);

    [, $second] = seatPlayers($game, 2);

    expect($second->defaultEmpireName())->toBe('Game ACME Seat 2');
    expect($second->empireName())->toBe('Game ACME Seat 2');
    expect($second->empire_name)->toBeNull();
});

test('a named empire keeps its name while the default stays available', function () {
    /*
     * The two methods answer different questions, which is why they are two. The screen prefills its
     * input from the default while still being able to say the empire has not been named — a single
     * resolved string could not tell an unnamed empire from one deliberately called "Game ACME Seat 1".
     */
    $game = Game::factory()->create(['short_name' => 'ACME']);

    [$seat] = seatPlayers($game, 1);

    $seat->empire_name = 'The Analytical Reach';
    $seat->save();

    expect($seat->empireName())->toBe('The Analytical Reach');
    expect($seat->defaultEmpireName())->toBe('Game ACME Seat 1');
});

test('a seat does not want to be emailed until it says so', function () {
    expect((new GameSeat)->email_notifications)->toBeFalse();

    $game = Game::factory()->create();

    [$seat] = seatPlayers($game, 1);

    expect($seat->fresh())->email_notifications->toBeFalse();
});
