<?php

use App\Actions\Games\AnnounceGameActivation;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Telling the players their game has started
|--------------------------------------------------------------------------
|
| `App\Actions\Games\AnnounceGameActivation` runs after both status endpoints save, and holds the whole
| rule so the two controllers cannot drift. Two halves of it are asserted here and neither is visible
| from either controller:
|
| - **`wasChanged()`, not `status === Active`.** A status form posts the status it is showing, so
|   saving an already-active game is the ordinary result of pressing the button twice. The test that
|   earns its place saves `active` twice and asserts the second sends nothing.
| - **Opt-in and nothing else.** `game_seats.email_notifications` defaults to false and only its own
|   holder can turn it on, so the set is "active player seats that asked" — never the gamemaster who
|   pressed the button, and never somebody who has left.
|
| Read through the **array mail transport** rather than `Notification::fake()`, for the reason
| `.ai/rules/invitations.md` gives: a fake still passes when the body never contained the link, and the
| link is the thing this email exists to carry.
|
*/

/**
 * Every message the application has actually sent, as raw HTML bodies.
 *
 * @return array<int, string>
 */
function sentEmailBodies(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    return $transport->messages()
        ->map(function ($sent): string {
            $message = $sent->getOriginalMessage();

            return $message instanceof Email ? (string) $message->getHtmlBody() : '';
        })
        ->all();
}

/**
 * A game that is fully generated, has its players placed, and is one save away from being active.
 *
 * @return array{0: Game, 1: User} the game, and a gamemaster who can activate it
 */
function gameReadyToStart(): array
{
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    withCompletedGeneration($game);
    withPlacedHomes($game);

    return [$game->fresh() ?? $game, $gamemaster];
}

test('a player who opted in is emailed when the gamemaster starts the game', function () {
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->create(['empire_name' => 'The Analytical Reach']);

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    $bodies = sentEmailBodies();

    expect($bodies)->toHaveCount(1);

    /* The link is the point, so it is asserted rather than the fact that something was sent. */
    expect($bodies[0])
        ->toContain(route('games.show', ['game' => $game]))
        ->toContain('The Analytical Reach');
});

test('a player who did not opt in is left alone', function () {
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->create();

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toBeEmpty();
});

test('a retired player who had opted in is not emailed', function () {
    /*
     * They are out of the game; the opt-in on their row is a record of what they wanted while they
     * were in it. `activeSeats()` is what excludes them, and a query over `seats()` would not.
     */
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->retired()->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toBeEmpty();
});

test('a gamemaster who opted in is not emailed about their own game', function () {
    /*
     * They pressed the button. The filter is on `GameRole::Player`, not on "everybody at the game".
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $game->seats()->where('user_id', $gamemaster->id)->update(['email_notifications' => true]);

    withCompletedGeneration($game);
    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game->fresh()]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toBeEmpty();
});

test('saving active on an already active game sends nothing', function () {
    /*
     * **The `wasChanged()` assertion.** A guard written as `status === Active` would pass every other
     * test in this file and mail everybody again each time the form was saved.
     */
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toHaveCount(1);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game->fresh()]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toHaveCount(1);
});

test('a game coming back from paused is announced again', function () {
    /*
     * The other side of `wasChanged()`, and the reason it is the right question: a game resuming has
     * genuinely started again, and the players who asked to hear about it are owed the same mail.
     */
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game->fresh()]), ['status' => 'paused'])
        ->assertRedirect();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game->fresh()]), ['status' => 'active'])
        ->assertRedirect();

    expect(sentEmailBodies())->toHaveCount(2);
});

test('moving a game to any other status announces nothing', function () {
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'archived'])
        ->assertRedirect();

    expect(sentEmailBodies())->toBeEmpty();
});

test('the administrator activating a game announces it too', function () {
    /*
     * Not a courtesy. A game activated from `/admin` has started just as much, and a player who opted
     * in is owed the mail whoever pressed the button — which is why the check lives in the action
     * rather than in the gamemaster's controller where the feature was written.
     */
    [$game] = gameReadyToStart();

    $member = User::factory()->create();
    GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    withPlacedHomes($game);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.games.update', ['game' => $game]), [
        'name' => $game->name,
        'short_name' => $game->short_name,
        'status' => 'active',
    ])->assertRedirect();

    expect(sentEmailBodies())->toHaveCount(1);
});

test('the email names the empire number and the turn', function () {
    [$game, $gamemaster] = gameReadyToStart();

    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->optedIn()->create();

    withPlacedHomes($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect();

    /*
     * Turn 0 is the setup turn, which is year 0 quarter 0 — the case `Game::yearAndQuarter()` gets
     * right only because PHP truncates toward zero. A game that has not been advanced reads that way
     * in the mailbox too.
     */
    expect(sentEmailBodies()[0])
        ->toContain('empire number '.$seat->number)
        ->toContain('turn 0')
        ->toContain('year 0, quarter 0');
});

test('the announcement reports how many players it wrote to', function () {
    $game = Game::factory()->create();
    gamemasterOf($game);

    $optedIn = User::factory()->create();
    $optedOut = User::factory()->create();

    GameSeat::factory()->for($game)->for($optedIn)->optedIn()->create();
    GameSeat::factory()->for($game)->for($optedOut)->create();

    withCompletedGeneration($game);
    withPlacedHomes($game);

    $game = $game->fresh() ?? $game;
    $game->status = GameStatus::Active;
    $game->save();

    expect(app(AnnounceGameActivation::class)->handle($game))->toBe(1);
});
