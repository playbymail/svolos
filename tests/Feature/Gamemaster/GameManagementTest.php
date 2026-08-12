<?php

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Http\Middleware\EnsureUserIsGamemaster;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The gamemaster's own screen for a game
|--------------------------------------------------------------------------
|
| `/gamemaster/games/{game}` is the member-facing counterpart of `/admin/games/{game}`, and the
| thing that opens it is an **active gamemaster seat at the game in the URL** — nothing else. Three
| refusals follow from that and are asserted below, because each one looks like an oversight to
| somebody reading the controller in isolation: a player at the same game is refused, a *retired*
| gamemaster is refused, and an administrator holding no seat is refused. The last is not a gap.
| Being an administrator says nothing about game membership; `/admin/games/{game}` is the screen
| that answers to `UserRole::Admin`, and an authorisation check reads exactly one of the two role
| systems (see `.ai/rules/roles.md`).
|
| The other rule this file pins is that a gamemaster **cannot rename a game**. It is enforced by
| `Gamemaster\GameStatusUpdateRequest` validating the status alone, so the test that earns its place
| posts a name and a short name alongside a valid status and asserts the status moved while the two
| names did not: a change that added either field to those rules would pass every other test here.
|
*/

/*
 * `gamemasterOf()` — a member holding an active gamemaster seat at a game — and
 * `executableSourceOf()` both live in `tests/Pest.php`, because `GameSeatManagementTest` uses them
 * too and a helper declared in a test file is only loaded when that file is.
 */

test('a guest is redirected to login rather than told the game exists', function () {
    $game = Game::factory()->create();

    $this->get(route('gamemaster.games.show', ['game' => $game]))->assertRedirect(route('login'));
    $this->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'active'])
        ->assertRedirect(route('login'));
});

test('a member with no seat at all is forbidden', function () {
    $member = User::factory()->create();
    $game = Game::factory()->create();

    $this->actingAs($member)->get(route('gamemaster.games.show', ['game' => $game]))->assertForbidden();
});

test('a PLAYER at the very same game is forbidden', function () {
    /*
     * The seat exists and belongs to this game — only the role is wrong. A check that had drifted to
     * "holds a seat here" rather than "runs this game" would pass everything else in this file.
     */
    $player = User::factory()->create();
    $game = Game::factory()->create();

    GameSeat::factory()->for($game)->for($player)->create();

    $this->actingAs($player)->get(route('gamemaster.games.show', ['game' => $game]))->assertForbidden();
});

test('a RETIRED gamemaster is forbidden', function () {
    /*
     * Seats are retired, never deleted, so the row outlives the person's time in the game. `is_active`
     * is what says they are still in it — dropping that condition would hand every departed gamemaster
     * their old game back.
     */
    $departed = User::factory()->create();
    $game = Game::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($departed)->gamemaster()->retired()->create();

    $this->actingAs($departed)->get(route('gamemaster.games.show', ['game' => $game]))->assertForbidden();

    /* The row is still there. */
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a gamemaster of one game is forbidden from another game', function () {
    $theirs = Game::factory()->create();
    $other = Game::factory()->create();

    $gamemaster = gamemasterOf($theirs);

    $this->actingAs($gamemaster)->get(route('gamemaster.games.show', ['game' => $theirs]))->assertOk();
    $this->actingAs($gamemaster)->get(route('gamemaster.games.show', ['game' => $other]))->assertForbidden();
});

test('an administrator holding no seat is forbidden, and uses the admin screen instead', function () {
    /*
     * **Not a gap.** This screen answers to a seat and the admin one answers to `UserRole::Admin`;
     * a check that consulted both would be the merge `.ai/rules/roles.md` forbids. The second
     * assertion is what makes the first safe to keep: the administrator is not locked out of the
     * game, they are on the other screen.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)->get(route('gamemaster.games.show', ['game' => $game]))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.games.show', ['game' => $game]))->assertOk();
});

test('an administrator who does hold a gamemaster seat gets in through the seat', function () {
    $game = Game::factory()->create();
    $admin = User::factory()->admin()->create();

    GameSeat::factory()->for($game)->for($admin)->gamemaster()->create();

    $this->actingAs($admin)->get(route('gamemaster.games.show', ['game' => $game]))->assertOk();
});

test('an unverified gamemaster is sent to email verification', function () {
    $game = Game::factory()->create();
    $gamemaster = User::factory()->unverified()->create();

    GameSeat::factory()->for($game)->for($gamemaster)->gamemaster()->create();

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertRedirect(route('verification.notice'));
});

test('the screen carries the game, the roster and the pickers', function () {
    $game = Game::factory()->paused()->create(['name' => 'The Long Retreat', 'short_name' => 'RETREAT']);
    $gamemaster = gamemasterOf($game, ['name' => 'Ada Gamemaster']);
    $player = User::factory()->create(['name' => 'Grace Player']);

    GameSeat::factory()->for($game)->for($player)->create();

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            ->where('game.name', 'The Long Retreat')
            ->where('game.short_name', 'RETREAT')
            ->where('game.status', 'paused')
            ->where('game.seats_count', 2)
            ->where('game.active_seats_count', 2)
            ->has('seats', 2)
            ->has('roles', 2)
            ->has('statuses', count(GameStatus::cases()))
            ->has('assignableAccounts')
            ->etc(),
        );
});

test('each seat says what this gamemaster may do to it', function () {
    /*
     * The three flags are the screen's copy of the two refusals the controllers enforce. They are
     * asserted here as props rather than through the rendered page, and again as 403s in
     * `GameSeatManagementTest` — a flag that drifted from the check would hide a working control or
     * offer one that errors.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game, ['name' => 'Ada Gamemaster']);

    $peer = User::factory()->create(['name' => 'Bob Peer']);
    $player = User::factory()->create(['name' => 'Cleo Player']);
    $departed = User::factory()->create(['name' => 'Dot Departed']);

    GameSeat::factory()->for($game)->for($peer)->gamemaster()->create();
    GameSeat::factory()->for($game)->for($player)->create();
    GameSeat::factory()->for($game)->for($departed)->retired()->create();

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            ->has('seats', 4)
            /* Sorted active-first then by name: Ada, Bob, Cleo, then the retired Dot. */
            ->has('seats.0', fn (Assert $seat) => $seat
                ->where('user_name', 'Ada Gamemaster')
                ->where('is_self', true)
                /* Your own seat: no retiring yourself, and no taking your own role off. */
                ->where('can_retire', false)
                ->where('can_demote', false)
                ->etc(),
            )
            ->has('seats.1', fn (Assert $seat) => $seat
                ->where('user_name', 'Bob Peer')
                ->where('is_self', false)
                /* A peer may be retired but not demoted. */
                ->where('can_retire', true)
                ->where('can_demote', false)
                ->etc(),
            )
            ->has('seats.2', fn (Assert $seat) => $seat
                ->where('user_name', 'Cleo Player')
                ->where('can_retire', true)
                ->where('can_demote', true)
                ->etc(),
            )
            ->has('seats.3', fn (Assert $seat) => $seat
                ->where('user_name', 'Dot Departed')
                ->where('is_active', false)
                /* Already out of the game; the control on this row is reactivate. */
                ->where('can_retire', false)
                ->where('can_demote', true)
                ->etc(),
            ),
        );
});

test('a gamemaster can move the game to another status', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run', 'status' => GameStatus::Setup]);
    $gamemaster = gamemasterOf($game);

    $response = $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value]);

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Alpha Run is now active.',
    ]);

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('an archived game can be restored by its gamemaster', function () {
    /* Archived is a status like any other here — nothing forces a game forward or keeps it away. */
    $game = Game::factory()->archived()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('an unknown status is rejected and changes nothing', function () {
    $game = Game::factory()->paused()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => 'triumphant'])
        ->assertSessionHasErrors('status');

    expect($game->fresh()?->status)->toBe(GameStatus::Paused);
});

test('a posted name and short name are IGNORED while the status still saves', function () {
    /*
     * **The test this half of the file exists for.** A gamemaster may run a game and may not rename
     * it — a short name leaves the application in turn reports and generated file names, so renaming
     * one relabels artefacts that already exist. The request validates the status alone, so
     * `validated()` has no name to fill; adding either field to those rules is a one-line hole, and
     * this is what closes it.
     *
     * The status is asserted to have moved as well, so the test cannot pass merely because the whole
     * request was rejected.
     */
    $game = Game::factory()->create([
        'name' => 'Alpha Run',
        'short_name' => 'ALPHA',
        'status' => GameStatus::Setup,
    ]);
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), [
            'name' => 'Renamed By A Gamemaster',
            'short_name' => 'STOLEN',
            'status' => GameStatus::Active->value,
        ])
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $fresh = $game->fresh();

    expect($fresh?->name)->toBe('Alpha Run');
    expect($fresh?->short_name)->toBe('ALPHA');
    expect($fresh?->status)->toBe(GameStatus::Active);
});

test('the gamemaster area has no route that creates, deletes or lists games', function () {
    /*
     * A sweep rather than a list, so a route added later is covered without anybody adding a case.
     * A gamemaster runs a game they were given; creating one, deleting one — which cascades every
     * seat and with them the game's history — and browsing the installation's inventory are all the
     * administrator's, and each would be a plausible-looking addition to this area.
     */
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'gamemaster.'))
        ->values();

    expect($names->all())->toEqualCanonicalizing([
        'gamemaster.games.show',
        'gamemaster.games.update',
        'gamemaster.games.seats.store',
        'gamemaster.games.seats.role.update',
        'gamemaster.games.seats.retire',
        'gamemaster.games.seats.reactivate',
    ]);

    /* And no seat route accepts a DELETE: seats are retired, never deleted. */
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'gamemaster.'))
        ->each(fn (RoutingRoute $route) => expect($route->methods())->not->toContain('DELETE'));
});

test('every gamemaster route is gated by auth, verified and the gamemaster middleware', function () {
    /*
     * The same shape as `AdminAccessTest`'s sweep, and for the same reason: a new screen in this area
     * that forgets one of the three fails here without anybody remembering to add a case. `auth` has
     * to be present for the 403s above to be 403s only for signed-in accounts — a guest is redirected.
     */
    $gamemasterRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'gamemaster.'));

    expect($gamemasterRoutes)->not->toBeEmpty();

    $gamemasterRoutes->each(function (RoutingRoute $route): void {
        expect($route->gatherMiddleware())
            ->toContain('auth')
            ->toContain('verified')
            ->toContain('gamemaster');
    });
});

test('the gamemaster alias resolves to the gamemaster middleware', function () {
    expect(app(HttpKernel::class)->getMiddlewareAliases())
        ->toHaveKey('gamemaster', EnsureUserIsGamemaster::class);
});

test('the gamemaster middleware fails closed for a request with no authenticated user', function () {
    /*
     * Mounted without `auth`, and without a `{game}` parameter to resolve — both of the middleware's
     * guards have to refuse rather than throw or let the request through.
     */
    Route::middleware('gamemaster')->get('/testing/gamemaster-unguarded', fn () => 'reached');

    $this->get('/testing/gamemaster-unguarded')->assertForbidden();
});

test('every gamemaster route resolves its seat through the game in the URL', function () {
    /*
     * `Route::scopeBindings()`, asserted behaviourally: a seat belonging to another game must 404
     * rather than be written through this game's URL. Removing the call does not fail loudly — both
     * parameters bind independently and the write succeeds with a 302 — so the seat is re-read
     * afterwards to prove nothing happened.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $elsewhere = Game::factory()->create();
    $foreign = GameSeat::factory()->for($elsewhere)->create();

    $routes = [
        'gamemaster.games.seats.role.update' => ['role' => GameRole::Gamemaster->value],
        'gamemaster.games.seats.retire' => [],
        'gamemaster.games.seats.reactivate' => [],
    ];

    foreach ($routes as $name => $payload) {
        $this->actingAs($gamemaster)
            ->put(route($name, ['game' => $game, 'seat' => $foreign]), $payload)
            ->assertNotFound();
    }

    expect($foreign->fresh()?->role)->toBe(GameRole::Player);
    expect($foreign->fresh()?->is_active)->toBeTrue();

    /*
     * And the cause, named: removing `scopeBindings()` should fail with "the flag is gone" rather
     * than only with three 404s that quietly turned into 302s. The count is the positive control.
     */
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'gamemaster.'))
        ->filter(fn (RoutingRoute $route): bool => in_array('seat', $route->parameterNames(), true))
        ->tap(fn ($routes) => expect($routes)->toHaveCount(3))
        ->each(fn (RoutingRoute $route) => expect($route->enforcesScopedBindings())->toBeTrue());
});

test('the gamemaster middleware code mentions no application role', function () {
    /*
     * The mirror of `GameRoleSeparationTest`'s assertion about `EnsureUserIsAdmin`. That one says the
     * admin gate must never consult a seat; this one says the game gate must never consult
     * `users.role`. A behavioural test cannot see a check that is present but currently redundant, so
     * this reads the class itself, with comments stripped — the prose has to stay free to name both
     * systems in order to say they are unrelated.
     */
    $code = executableSourceOf(EnsureUserIsGamemaster::class);

    foreach (['UserRole', 'isAdmin', 'users.role', 'admin'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }

    /* The positive control: the stripping left the actual check behind. */
    expect($code)->toContain('GameRole::Gamemaster');
});
