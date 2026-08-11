<?php

use App\Enums\GameRole;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Game seats
|--------------------------------------------------------------------------
|
| Four rules are pinned here, each of which is the kind a later change undoes by accident.
|
| **Seats are retired, never deleted.** There is no destroy endpoint, and the sweep at the
| bottom of this file fails if one is ever added: engine history keeps referring to a seat, so
| deleting the row turns recorded history into a dangling reference. The screen looks incomplete
| without a delete button; it is not.
|
| **The uniqueness check counts retired seats.** An account that left a game still owns its row,
| so it can never get a second one — the way back is reactivation. Both the validation message
| and the assignable-accounts list are asserted against a *retired* holder, because a rule that
| only ever sees active seats is a rule that has already stopped working.
|
| **Seat routes are scoped to their game.** `Route::scopeBindings()` makes `{seat}` resolve
| through `Game::seats()`, so a seat id from game A 404s on game B's routes rather than being
| edited through the wrong game's URL. Every seat route is asserted, and the seat is checked
| afterwards to prove the 404 happened *before* the write rather than after it.
|
| **A game role grants nothing outside its game.** That half lives in GameRoleSeparationTest.
|
*/

/**
 * Every seat route, as [method, url factory] pairs taking the game and the seat.
 *
 * @return array<string, array{0: string, 1: Closure(Game, GameSeat): string}>
 */
function gameSeatRoutes(): array
{
    return [
        'role.update' => ['put', fn (Game $game, GameSeat $seat): string => route('admin.games.seats.role.update', ['game' => $game, 'seat' => $seat])],
        'retire' => ['put', fn (Game $game, GameSeat $seat): string => route('admin.games.seats.retire', ['game' => $game, 'seat' => $seat])],
        'reactivate' => ['put', fn (Game $game, GameSeat $seat): string => route('admin.games.seats.reactivate', ['game' => $game, 'seat' => $seat])],
    ];
}

test('a guest is redirected to login from every seat route', function () {
    $game = Game::factory()->create();
    $seat = GameSeat::factory()->for($game)->create();

    $this->post(route('admin.games.seats.store', ['game' => $game]), [
        'user_id' => User::factory()->create()->id,
        'role' => GameRole::Player->value,
    ])->assertRedirect(route('login'));

    foreach (gameSeatRoutes() as [$method, $url]) {
        $this->{$method}($url($game, $seat), ['role' => GameRole::Gamemaster->value])
            ->assertRedirect(route('login'));
    }

    expect($game->seats()->count())->toBe(1);
    expect($seat->fresh()?->role)->toBe(GameRole::Player);
    expect($seat->fresh()?->is_active)->toBeTrue();
});

test('a member is forbidden from every seat route', function () {
    $member = User::factory()->create();
    $game = Game::factory()->create();
    $seat = GameSeat::factory()->for($game)->create();

    $this->actingAs($member)->post(route('admin.games.seats.store', ['game' => $game]), [
        'user_id' => $member->id,
        'role' => GameRole::Gamemaster->value,
    ])->assertForbidden();

    foreach (gameSeatRoutes() as [$method, $url]) {
        $this->actingAs($member)
            ->{$method}($url($game, $seat), ['role' => GameRole::Gamemaster->value])
            ->assertForbidden();
    }

    expect($game->seats()->count())->toBe(1);
    expect($seat->fresh()?->role)->toBe(GameRole::Player);
    expect($seat->fresh()?->is_active)->toBeTrue();
});

test('an unverified administrator is sent to email verification from every seat route', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $game = Game::factory()->create();
    $seat = GameSeat::factory()->for($game)->create();

    $this->actingAs($admin)->post(route('admin.games.seats.store', ['game' => $game]), [
        'user_id' => User::factory()->create()->id,
        'role' => GameRole::Player->value,
    ])->assertRedirect(route('verification.notice'));

    foreach (gameSeatRoutes() as [$method, $url]) {
        $this->actingAs($admin)
            ->{$method}($url($game, $seat), ['role' => GameRole::Gamemaster->value])
            ->assertRedirect(route('verification.notice'));
    }
});

test('an administrator can seat an account with a game role', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $player = User::factory()->create(['name' => 'Grace Hopper']);

    $response = $this->actingAs($admin)->post(route('admin.games.seats.store', ['game' => $game]), [
        'user_id' => $player->id,
        'role' => GameRole::Gamemaster->value,
    ]);

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper joined Alpha Run as a gamemaster.',
    ]);

    $seat = $game->seats()->sole();

    expect($seat->user_id)->toBe($player->id);
    expect($seat->role)->toBe(GameRole::Gamemaster);
    expect($seat->is_active)->toBeTrue();
});

test('a seat cannot be created with an unknown account or an unknown role', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.seats.store', ['game' => $game]), [
            'user_id' => 9999,
            'role' => GameRole::Player->value,
        ])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($admin)
        ->post(route('admin.games.seats.store', ['game' => $game]), [
            'user_id' => User::factory()->create()->id,
            'role' => 'overlord',
        ])
        ->assertSessionHasErrors('role');

    expect($game->seats()->count())->toBe(0);
});

test('a second seat for an account that already has an active one is rejected', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    $player = User::factory()->create();

    GameSeat::factory()->for($game)->for($player)->create();

    $this->actingAs($admin)
        ->post(route('admin.games.seats.store', ['game' => $game]), [
            'user_id' => $player->id,
            'role' => GameRole::Gamemaster->value,
        ])
        ->assertSessionHasErrors([
            'user_id' => 'That account already has a seat in this game.',
        ]);

    expect($game->seats()->count())->toBe(1);
});

test('a second seat for an account whose seat has been RETIRED is rejected with the same message', function () {
    /*
     * The case the rule exists for. A `->where('is_active', true)` added to the uniqueness rule would
     * let this through, the unique index on `(game_id, user_id)` would then throw a database error
     * instead of a validation message, and "bringing somebody back is a reactivation" would silently
     * stop being true. The retired seat is checked afterwards to prove nothing was written.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    $departed = User::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($departed)->retired()->gamemaster()->create();

    $this->actingAs($admin)
        ->post(route('admin.games.seats.store', ['game' => $game]), [
            'user_id' => $departed->id,
            'role' => GameRole::Player->value,
        ])
        ->assertSessionHasErrors([
            'user_id' => 'That account already has a seat in this game.',
        ]);

    expect($game->seats()->count())->toBe(1);
    expect($seat->fresh()?->is_active)->toBeFalse();
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('the assignable accounts list excludes holders of retired seats as well as active ones', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $game = Game::factory()->create();

    $active = User::factory()->create(['name' => 'Bea Active']);
    $departed = User::factory()->create(['name' => 'Cyd Departed']);
    $free = User::factory()->create(['name' => 'Dee Free']);

    GameSeat::factory()->for($game)->for($active)->create();
    GameSeat::factory()->for($game)->for($departed)->retired()->create();

    /* A seat at a *different* game must not remove an account from this game's list. */
    $elsewhere = User::factory()->create(['name' => 'Eve Elsewhere']);
    GameSeat::factory()->for($elsewhere)->create();

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignableAccounts', 3)
            ->where('assignableAccounts.0.name', 'Ada Admin')
            ->where('assignableAccounts.1.name', 'Dee Free')
            ->where('assignableAccounts.2.name', 'Eve Elsewhere')
            ->where('assignableAccounts.1.id', $free->id)
            ->where('assignableAccounts.2.id', $elsewhere->id),
        );
});

test('an administrator can change the game role a seat holds', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $player = User::factory()->create(['name' => 'Grace Hopper']);
    $seat = GameSeat::factory()->for($game)->for($player)->create();

    $response = $this->actingAs($admin)->put(
        route('admin.games.seats.role.update', ['game' => $game, 'seat' => $seat]),
        ['role' => GameRole::Gamemaster->value],
    );

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper is now a gamemaster in Alpha Run.',
    ]);

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('changing a seat role cannot change whether the seat is active', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    $seat = GameSeat::factory()->for($game)->retired()->create();

    $this->actingAs($admin)->put(
        route('admin.games.seats.role.update', ['game' => $game, 'seat' => $seat]),
        ['role' => GameRole::Gamemaster->value, 'is_active' => true],
    )->assertSessionHasNoErrors();

    /* The role changed; `is_active` did not, because it is not fillable and not validated here. */
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
    expect($seat->fresh()?->is_active)->toBeFalse();
});

test('is_active is absent from the game seat fillable attributes', function () {
    /*
     * Pinned at the model rather than only through the endpoint, because an endpoint test still passes
     * when `is_active` becomes fillable — the request simply does not send it today.
     */
    expect((new GameSeat)->getFillable())->toBe(['user_id', 'role'])
        ->and((new GameSeat)->getFillable())->not->toContain('is_active');
});

test('an administrator can retire a seat, and the row survives', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $player = User::factory()->create(['name' => 'Grace Hopper']);
    $seat = GameSeat::factory()->for($game)->for($player)->gamemaster()->create();

    $response = $this->actingAs($admin)->put(
        route('admin.games.seats.retire', ['game' => $game, 'seat' => $seat]),
    );

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Grace Hopper's seat in Alpha Run was retired.",
    ]);

    /* Retiring is not a delete: the row is still there, and it keeps its game role. */
    expect(GameSeat::query()->whereKey($seat->getKey())->exists())->toBeTrue();
    expect($seat->fresh()?->is_active)->toBeFalse();
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
    expect($game->seats()->count())->toBe(1);
    expect($game->activeSeats()->count())->toBe(0);
});

test('an administrator can reactivate a retired seat, keeping its game role', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $player = User::factory()->create(['name' => 'Grace Hopper']);
    $seat = GameSeat::factory()->for($game)->for($player)->retired()->gamemaster()->create();

    $response = $this->actingAs($admin)->put(
        route('admin.games.seats.reactivate', ['game' => $game, 'seat' => $seat]),
    );

    $response->assertRedirect(route('admin.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Grace Hopper's seat in Alpha Run was reactivated.",
    ]);

    expect($seat->fresh()?->is_active)->toBeTrue();
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);

    /* Reactivation is the *only* way back in, so there must still be exactly one row. */
    expect($game->seats()->where('user_id', $player->id)->count())->toBe(1);
});

test('there is no seat destroy endpoint', function () {
    /*
     * Written as a sweep of the route collection rather than a single assertion, so that adding a
     * `DELETE admin/games/{game}/seats/{seat}` fails here whatever it is named. Seats are retired
     * because engine history keeps referring to them; a delete button would look like the missing
     * piece and would be the bug.
     */
    $seatRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.games.seats.'));

    expect($seatRoutes)->not->toBeEmpty();

    expect($seatRoutes->pluck('action.as')->sort()->values()->all())->toBe([
        'admin.games.seats.reactivate',
        'admin.games.seats.retire',
        'admin.games.seats.role.update',
        'admin.games.seats.store',
    ]);

    $seatRoutes->each(function (RoutingRoute $route): void {
        expect($route->methods())->not->toContain('DELETE');
    });

    /* And nothing anywhere else deletes a seat by URL either. */
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_contains((string) $route->uri(), 'seats'))
        ->each(function (RoutingRoute $route): void {
            expect($route->methods())->not->toContain('DELETE');
        });
});

test("a seat from another game 404s on every one of this game's seat routes", function () {
    /*
     * The `Route::scopeBindings()` guarantee. Without it, `{game}` and `{seat}` bind independently and
     * game B's URL happily edits game A's seat; with it, `{seat}` is resolved through `Game::seats()`
     * and the mismatch is a 404.
     *
     * The seat is re-read after each attempt so the assertion is that the write never happened, not
     * merely that the response was a 404 after the fact.
     */
    $admin = User::factory()->admin()->create();

    $gameA = Game::factory()->create();
    $gameB = Game::factory()->create();

    $seatAtA = GameSeat::factory()->for($gameA)->create();

    foreach (gameSeatRoutes() as $name => [$method, $url]) {
        $this->actingAs($admin)
            ->{$method}($url($gameB, $seatAtA), ['role' => GameRole::Gamemaster->value])
            ->assertNotFound();

        $fresh = $seatAtA->fresh();

        expect($fresh)->not->toBeNull();
        expect($fresh?->game_id)->toBe($gameA->id);
        expect($fresh?->role)->toBe(GameRole::Player, "role changed through {$name}");
        expect($fresh?->is_active)->toBeTrue("is_active changed through {$name}");
    }

    /* The same seat through its own game still works, so the 404 is about scoping and nothing else. */
    $this->actingAs($admin)
        ->put(route('admin.games.seats.role.update', ['game' => $gameA, 'seat' => $seatAtA]), [
            'role' => GameRole::Gamemaster->value,
        ])
        ->assertRedirect(route('admin.games.show', ['game' => $gameA]));

    expect($seatAtA->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a retired seat from another game 404s too rather than being reactivated elsewhere', function () {
    $admin = User::factory()->admin()->create();

    $gameA = Game::factory()->create();
    $gameB = Game::factory()->create();

    $seatAtA = GameSeat::factory()->for($gameA)->retired()->create();

    $this->actingAs($admin)
        ->put(route('admin.games.seats.reactivate', ['game' => $gameB, 'seat' => $seatAtA]))
        ->assertNotFound();

    expect($seatAtA->fresh()?->is_active)->toBeFalse();
});

test('a seat id that does not exist at all 404s', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.games.seats.retire', ['game' => $game, 'seat' => 9999]))
        ->assertNotFound();
});

test('every seat route really carries the scoped binding flag', function () {
    /*
     * The behavioural 404 tests above are the real proof; this one names the cause, so that removing
     * `scopeBindings()` fails with "the flag is gone" rather than only with four confusing 404s that
     * turned into 302s.
     */
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('seat', $route->parameterNames(), true))
        ->tap(fn ($routes) => expect($routes)->toHaveCount(3))
        ->each(function (RoutingRoute $route): void {
            expect($route->enforcesScopedBindings())->toBeTrue();
        });
});

test('an account can hold seats at two different games', function () {
    /*
     * The uniqueness rule is scoped to one game, not global. If the `game_id` condition were ever
     * dropped from it, this is what would start failing.
     */
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    $gameA = Game::factory()->create();
    $gameB = Game::factory()->create();

    GameSeat::factory()->for($gameA)->for($player)->create();

    $this->actingAs($admin)
        ->post(route('admin.games.seats.store', ['game' => $gameB]), [
            'user_id' => $player->id,
            'role' => GameRole::Gamemaster->value,
        ])
        ->assertSessionHasNoErrors();

    expect($gameB->seats()->sole()->role)->toBe(GameRole::Gamemaster);
    expect($gameA->seats()->sole()->role)->toBe(GameRole::Player);
});
