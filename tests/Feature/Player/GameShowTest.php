<?php

use App\Enums\GameStatus;
use App\Http\Middleware\EnsureUserIsPlayer;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The player's own screen for a game
|--------------------------------------------------------------------------
|
| `/games/{game}` is the counterpart of `/gamemaster/games/{game}`, and the thing that opens it is an
| **active player seat at the game in the URL** — nothing else. Four refusals follow and are all
| asserted below, because three of them look like oversights to somebody reading the middleware in
| isolation: a member with no seat, a *retired* player, an administrator holding no seat, and — the
| one worth reading twice — a **gamemaster at the very same game**. That last is not a gap. This
| screen is about an empire, and a gamemaster has no home stellium, no entities and no empire number;
| they have `/gamemaster/games/{game}`, which answers their question far better.
|
| The other rule this file pins is the one behind the whole feature: **a player is not shown the
| cluster.** `PresentsGeneration::presentLocations()` is omniscient — a hundred systems, every home,
| every player's account name — and `Player\GameController` shapes the seat's own home instead. The
| test that earns its place seats two players at one game and asserts that nothing belonging to the
| second appears anywhere in the first's payload: a change that "simplified" the controller into
| reusing the shared presenter would pass every other test here.
|
*/

test('a guest is redirected to login rather than told the game exists', function () {
    $game = Game::factory()->create();

    $this->get(route('games.show', ['game' => $game]))->assertRedirect(route('login'));
    $this->put(route('games.profile.update', ['game' => $game]), ['empire_name' => 'X', 'email_notifications' => '0'])
        ->assertRedirect(route('login'));
});

test('a member with no seat at all is forbidden', function () {
    $member = User::factory()->create();
    $game = Game::factory()->create();

    $this->actingAs($member)->get(route('games.show', ['game' => $game]))->assertForbidden();
});

test('a GAMEMASTER at the very same game is forbidden', function () {
    /*
     * The seat exists and belongs to this game — only the role is wrong. A gate that had drifted to
     * "holds a seat here" rather than "plays this game" would pass everything else in this file, and
     * would hand a gamemaster a screen with no empire on it.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)->get(route('games.show', ['game' => $game]))->assertForbidden();
});

test('a retired player is forbidden', function () {
    /*
     * Seats are retired rather than deleted, so the row outlives the person's time in the game.
     * `is_active` is what says they are still in it, and a gate reading the row alone would let
     * somebody who has left back into their old empire.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->retired()->create();

    $this->actingAs($member)->get(route('games.show', ['game' => $game]))->assertForbidden();
});

test('an administrator holding no seat is forbidden', function () {
    /*
     * Not an oversight. Being an administrator says nothing about game membership, and an
     * authorisation check reads exactly one of the two role systems — see `.ai/rules/roles.md`.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)->get(route('games.show', ['game' => $game]))->assertForbidden();
});

test('a player at another game cannot reach this one', function () {
    $member = User::factory()->create();
    $theirs = Game::factory()->create();
    $someoneElses = Game::factory()->create();

    GameSeat::factory()->for($theirs)->for($member)->create();

    $this->actingAs($member)->get(route('games.show', ['game' => $someoneElses]))->assertForbidden();
});

test('the player sees their game, their empire and the calendar', function () {
    $game = Game::factory()->create(['short_name' => 'ACME', 'turn' => 5]);
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('player/games/Show')
            /* The whole key set on both, so a field added without a type or a test fails here. */
            ->has('game', fn (Assert $prop) => $prop
                ->where('id', $game->id)
                ->where('name', $game->name)
                ->where('short_name', 'ACME')
                ->where('status', 'setup')
                ->where('status_label', 'Setup')
                ->where('is_active', false)
                ->where('turn', 5)
                /* Turn 5 opens year 1. See `Game::yearAndQuarter()`. */
                ->where('year', 1)
                ->where('quarter', 1)
            )
            ->has('seat', fn (Assert $prop) => $prop
                ->where('id', $seat->id)
                ->where('number', 1)
                ->where('empire_name', null)
                ->where('empire_name_default', 'Game ACME Seat 1')
                ->where('email_notifications', false)
            )
            ->has('locations', 0)
            ->where('homeSystem', null)
        );
});

test('the seed and the roster are not in the payload', function () {
    /*
     * The omissions are the feature. A player exploring a world must not be handed the number it was
     * drawn from, and who else is playing is the game's to reveal rather than the screen's.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->create();
    seatPlayers($game, 2);

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('player/games/Show')
            ->missing('game.seed')
            ->missing('seats')
            ->missing('generation')
        );
});

test('a game in setup shows no cluster and no probe report', function () {
    /*
     * A game in setup is still being built: its world can be thrown away and generated again, so
     * anything shown from it is provisional. The homes exist here — the withholding is the status,
     * not the absence of data.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->create();
    withCompletedGeneration($game);
    withPlacedHomes($game);

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('locations', 0)
            ->where('homeSystem', null)
            ->where('game.is_active', false)
        );
});

test('an active game shows exactly one location: the player own home', function () {
    $game = Game::factory()->create();
    $member = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($member)->create();

    withCompletedGeneration($game);
    withPlacedHomes($game);
    $game->update(['status' => GameStatus::Active]);

    $home = $seat->fresh()?->homeStellium;

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('locations.0.id', $home?->location_id)
            ->where('locations.0.home_seat_id', $seat->id)
        );
});

test('another player home is nowhere in the payload', function () {
    /*
     * **The test this file exists for.** Both players are placed and both homes are in the database;
     * the first must be sent one of them. Reusing `presentLocations()` here would send a hundred
     * locations with everybody's name attached, and every other assertion in this file would still
     * pass.
     */
    $game = Game::factory()->create();
    $mine = User::factory()->create(['name' => 'Mine']);
    $theirs = User::factory()->create(['name' => 'Cartographer Vex']);

    $mySeat = GameSeat::factory()->for($game)->for($mine)->create();
    $theirSeat = GameSeat::factory()->for($game)->for($theirs)->create(['empire_name' => 'The Vexian Reach']);

    withCompletedGeneration($game);
    withPlacedHomes($game);
    $game->update(['status' => GameStatus::Active]);

    $theirHome = $theirSeat->fresh()?->homeStellium;

    expect($theirHome)->not->toBeNull();

    $response = $this->actingAs($mine)->get(route('games.show', ['game' => $game]));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('locations', 1)
        ->where('locations.0.home_seat_id', $mySeat->id)
    );

    /*
     * Asserted against the whole rendered payload rather than against a prop path, because the point
     * is that these appear *nowhere* — not that one particular key is clean.
     */
    $payload = json_encode($response->viewData('page'));

    expect($payload)
        ->not->toContain('Cartographer Vex')
        ->not->toContain('The Vexian Reach')
        ->not->toContain('"id":'.$theirHome?->location_id.',"ordinal"');
});

test('the map names the empire rather than the account', function () {
    /*
     * Inside a game a player is their empire; the account behind it is the administrator's business.
     * The map prints this under "Home of", so the name has to be the one the game uses.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create(['name' => 'Ada Lovelace']);

    GameSeat::factory()->for($game)->for($member)->create(['empire_name' => 'The Analytical Reach']);

    withCompletedGeneration($game);
    withPlacedHomes($game);
    $game->update(['status' => GameStatus::Active]);

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.home_player_name', 'The Analytical Reach')
        );
});

test('an unnamed empire falls back to the game and seat', function () {
    $game = Game::factory()->create(['short_name' => 'RUN-1']);
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->create();

    withCompletedGeneration($game);
    withPlacedHomes($game);
    $game->update(['status' => GameStatus::Active]);

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.home_player_name', 'Game RUN-1 Seat 1')
        );
});

test('a player seated after the homes were arranged is shown no cluster rather than an error', function () {
    /*
     * The one way a fully generated, active game can still have a player with nowhere to begin — see
     * `Game::playersWithoutHomeStellium()`. The screen has to say so; it must not 500 on a null home.
     */
    $game = Game::factory()->create();
    $early = User::factory()->create();

    GameSeat::factory()->for($game)->for($early)->create();

    withCompletedGeneration($game);
    withPlacedHomes($game);
    $game->update(['status' => GameStatus::Active]);

    $late = User::factory()->create();
    GameSeat::factory()->for($game)->for($late)->create();

    $this->actingAs($late)
        ->get(route('games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('locations', 0)
            ->where('homeSystem', null)
            ->where('game.is_active', true)
        );
});

test('the probe report carries the home system stars, planets and holdings', function () {
    /*
     * The real generators, all the way through the units stage, because what is being asserted is
     * that a player is shown a *finished* system: the planets around their star and the colony and
     * ship standing on their home world.
     */
    $game = Game::factory()->create();
    $member = User::factory()->create();

    GameSeat::factory()->for($game)->for($member)->create();

    withAcceptedUnits($game);
    $game->update(['status' => GameStatus::Active]);

    $this->actingAs($member)
        ->get(route('games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('homeSystem', fn (Assert $detail) => $detail
                ->has('id')
                ->has('ordinal')
                /* A home system is single-star by construction, with the template's nine planets. */
                ->has('stars', 1)
                ->has('stars.0.planets', 9)
                ->has('stars.0.planets.0.type')
                ->has('stars.0.planets.0.habitability')
                ->etc()
            )
        );

    /* The colony and the ship are somewhere in that system, and both are this player's. */
    $response = $this->actingAs($member)->get(route('games.show', ['game' => $game]));
    $payload = (string) json_encode($response->viewData('page'));

    expect($payload)->toContain('"type":"colony"')->toContain('"type":"ship"');
});

test('every player route is gated by auth, verified and the player middleware', function () {
    /*
     * A sweep rather than a list, and the same shape as `AdminAccessTest`'s: a screen added to this
     * area that forgets one of the three fails here without anybody remembering to add a case. `auth`
     * has to be present for the 403s above to be 403s only for signed-in accounts — a guest is
     * redirected.
     */
    $playerRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'games.'));

    expect($playerRoutes)->not->toBeEmpty();

    $playerRoutes->each(function (RoutingRoute $route): void {
        expect($route->gatherMiddleware())
            ->toContain('auth')
            ->toContain('verified')
            ->toContain('player');
    });
});

test('the player alias resolves to the player middleware', function () {
    expect(app(HttpKernel::class)->getMiddlewareAliases())
        ->toHaveKey('player', EnsureUserIsPlayer::class);
});

test('the player middleware fails closed for a request with no authenticated user', function () {
    /*
     * Mounted without `auth`, and without a `{game}` parameter to resolve — both of the middleware's
     * guards have to refuse rather than throw or let the request through.
     */
    Route::middleware('player')->get('/testing/player-unguarded', fn () => 'reached');

    $this->get('/testing/player-unguarded')->assertForbidden();
});

test('the player gate consults a seat and never the application role', function () {
    /*
     * The same source assertion the gamemaster gate carries, and for the same reason: behaviour
     * cannot see the difference between "reads a seat" and "reads a seat, or is an administrator"
     * until somebody adds the second half. Comments are stripped, so the prose above the class is
     * free to name both systems in order to say they are unrelated.
     */
    $source = executableSourceOf(EnsureUserIsPlayer::class);

    expect($source)
        ->not->toContain('UserRole')
        ->not->toContain('isAdmin')
        ->not->toContain("'role' => ")
        ->toContain('GameRole::Player');
});
