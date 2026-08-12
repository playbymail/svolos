<?php

use App\Enums\GameRole;
use App\Http\Controllers\Gamemaster\GameSeatController;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The roster, as managed by a gamemaster
|--------------------------------------------------------------------------
|
| A gamemaster gets the administrator's roster tools on their own game, minus **two refusals** that
| are the reason this file exists. Both are 403s rather than validation errors: the value posted is
| well formed, it is the requester who may not post it.
|
| **You cannot retire yourself.** Leaving a game you run removes the last person able to reach the
| screen if you are the only gamemaster, and is indistinguishable from an accident. An administrator
| retires you, through `/admin/games/{game}`.
|
| **You cannot take the gamemaster role off a seat.** Handing it out is allowed — promoting a player
| is how a game gets a second pair of hands — but a gamemaster demoting a peer is one gamemaster
| ejecting another from the only screen that can undo it. The check is written as "the seat is a
| gamemaster's and the new role is not", not "the new role is player", so a third game role added
| later cannot be used as a way around it; there is a test for exactly that shape.
|
| Everything the administrator's roster guarantees still holds here and is asserted where it could
| plausibly have been re-implemented differently: seats are retired rather than deleted, and the
| uniqueness check counts retired seats so coming back is a reactivation.
|
| One consequence is deliberate and worth naming rather than discovering: a gamemaster who may not
| demote a peer **may still retire them**. That is the intended shape — retiring is reversible and
| leaves the row and its role intact, demoting rewrites what the seat was.
|
*/

test('a gamemaster can seat an account, including as another gamemaster', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $gamemaster = gamemasterOf($game);
    $recruit = User::factory()->create(['name' => 'Grace Hopper']);

    $response = $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.seats.store', ['game' => $game]), [
            'user_id' => $recruit->id,
            'role' => GameRole::Gamemaster->value,
        ]);

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper joined Alpha Run as a gamemaster.',
    ]);

    $seat = $game->seats()->where('user_id', $recruit->id)->sole();

    expect($seat->role)->toBe(GameRole::Gamemaster);
    expect($seat->is_active)->toBeTrue();
});

test('a second seat for an account whose seat was retired is rejected with the same message', function () {
    /*
     * The gamemaster's screen posts against the same uniqueness rule the administrator's does — it
     * lives in `App\Concerns\GameValidationRules` so the two cannot drift — and that rule counts
     * retired seats. Reactivation is the way back in, never a second row.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);
    $departed = User::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($departed)->retired()->create();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.seats.store', ['game' => $game]), [
            'user_id' => $departed->id,
            'role' => GameRole::Player->value,
        ])
        ->assertSessionHasErrors([
            'user_id' => 'That account already has a seat in this game.',
        ]);

    expect($game->seats()->count())->toBe(2);
    expect($seat->fresh()?->is_active)->toBeFalse();
});

test('a gamemaster can promote a player to gamemaster', function () {
    /* Handing the role out is the allowed direction, and the one a game needs to grow. */
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $gamemaster = gamemasterOf($game);
    $player = User::factory()->create(['name' => 'Grace Hopper']);

    $seat = GameSeat::factory()->for($game)->for($player)->create();

    $response = $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Gamemaster->value,
        ]);

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Grace Hopper is now a gamemaster in Alpha Run.',
    ]);

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a gamemaster CANNOT demote another gamemaster to a player', function () {
    /* **The refusal this file exists for.** Only an administrator takes the role back off. */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);
    $peer = User::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($peer)->gamemaster()->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Player->value,
        ])
        ->assertForbidden();

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a gamemaster cannot demote THEMSELVES either', function () {
    /*
     * The same rule seen from the other side, and worth its own test: demoting yourself is the one
     * demotion a "you may not act on a peer" reading of the rule would have let through, and it ends
     * with nobody able to reach the screen.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $seat = $game->seats()->where('user_id', $gamemaster->id)->sole();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Player->value,
        ])
        ->assertForbidden();

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('setting a gamemaster seat to gamemaster is allowed because it changes nothing', function () {
    /*
     * The refusal is about losing the role, not about touching the row. Refusing a no-op would report
     * a boundary to somebody who has not crossed it, and would make the picker on the screen error on
     * a resubmit.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);
    $peer = User::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($peer)->gamemaster()->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Gamemaster->value,
        ])
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('the demotion check refuses anything that is not still a gamemaster', function () {
    /*
     * Pins the *shape* of the check rather than its current effect. Written as "the new role is
     * player" it would pass every test above and quietly stop working the day a third game role is
     * added — so this reads the source and asserts the comparison is the negative one.
     */
    $code = (string) preg_replace('/\s+/', ' ', executableSourceOf(GameSeatController::class));

    expect($code)->toContain('$seat->role === GameRole::Gamemaster && $role !== GameRole::Gamemaster');

    /*
     * And nowhere is the refusal phrased as a comparison against the lesser role — which is the shape
     * this would drift into, and the shape that stops covering everything the day a third role exists.
     * `GameRole::Player` still appears as the unreachable fallback in `updateRole()`; what must not
     * appear is an equality test against it.
     */
    expect($code)->not->toContain('=== GameRole::Player');
});

test('a gamemaster can retire somebody else and the seat is kept', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $gamemaster = gamemasterOf($game);
    $player = User::factory()->create(['name' => 'Grace Hopper']);

    $seat = GameSeat::factory()->for($game)->for($player)->create();

    $response = $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.retire', ['game' => $game, 'seat' => $seat]));

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Grace Hopper's seat in Alpha Run was retired.",
    ]);

    /* Retired, not deleted: the row and its role survive so the game's history keeps resolving. */
    expect($seat->fresh()?->is_active)->toBeFalse();
    expect($seat->fresh()?->role)->toBe(GameRole::Player);
    expect($game->seats()->count())->toBe(2);
});

test('a gamemaster CANNOT retire themselves', function () {
    /* **The other refusal this file exists for.** */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $seat = $game->seats()->where('user_id', $gamemaster->id)->sole();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.retire', ['game' => $game, 'seat' => $seat]))
        ->assertForbidden();

    expect($seat->fresh()?->is_active)->toBeTrue();
});

test('the self-retire refusal is about the seat holder, not about the role it holds', function () {
    /*
     * A gamemaster who has been given a *player* seat at their own game cannot exist — the unique
     * index allows one seat per account per game — so the way to check the comparison is not
     * accidentally about roles is to retire a peer gamemaster, which must succeed.
     *
     * That is a deliberate consequence rather than an oversight: retiring is reversible and leaves
     * the row and its role intact, so a gamemaster removing a peer from the roster is not the same
     * act as rewriting what that seat was.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);
    $peer = User::factory()->create();

    $peerSeat = GameSeat::factory()->for($game)->for($peer)->gamemaster()->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.retire', ['game' => $game, 'seat' => $peerSeat]))
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($peerSeat->fresh()?->is_active)->toBeFalse();
    expect($peerSeat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a gamemaster can reactivate a retired seat and it keeps the role it had', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $gamemaster = gamemasterOf($game);
    $returning = User::factory()->create(['name' => 'Grace Hopper']);

    $seat = GameSeat::factory()->for($game)->for($returning)->gamemaster()->retired()->create();

    $response = $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.reactivate', ['game' => $game, 'seat' => $seat]));

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Grace Hopper's seat in Alpha Run was reactivated.",
    ]);

    /*
     * Back as a gamemaster. Restoring the role a seat already held is not the same act as handing the
     * role out, and reactivating somebody is not a decision about what they should be called.
     */
    expect($seat->fresh()?->is_active)->toBeTrue();
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
});

test('a role change never moves a seat in or out of the game', function () {
    /*
     * `is_active` is out of `GameSeat`'s `#[Fillable]` and is not validated by either role request,
     * so a posted one is ignored. Asserted on the gamemaster's endpoint too, because it writes the
     * role through a different controller than the administrator's does.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);
    $departed = User::factory()->create();

    $seat = GameSeat::factory()->for($game)->for($departed)->retired()->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Gamemaster->value,
            'is_active' => true,
        ])
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
    expect($seat->fresh()?->is_active)->toBeFalse();
});

test('a player at the game is forbidden from every seat route', function () {
    /*
     * The gate is a gamemaster seat, not any seat. Asserted per-route rather than only on the screen,
     * because a controller reachable without the middleware would be the way this leaks.
     */
    $game = Game::factory()->create();
    $player = User::factory()->create();

    GameSeat::factory()->for($game)->for($player)->create();

    $target = GameSeat::factory()->for($game)->create();

    $this->actingAs($player)
        ->post(route('gamemaster.games.seats.store', ['game' => $game]), [
            'user_id' => User::factory()->create()->id,
            'role' => GameRole::Gamemaster->value,
        ])
        ->assertForbidden();

    foreach (['role.update', 'retire', 'reactivate'] as $action) {
        $this->actingAs($player)
            ->put(route("gamemaster.games.seats.{$action}", ['game' => $game, 'seat' => $target]), [
                'role' => GameRole::Gamemaster->value,
            ])
            ->assertForbidden();
    }

    expect($game->seats()->count())->toBe(2);
    expect($target->fresh()?->role)->toBe(GameRole::Player);
    expect($target->fresh()?->is_active)->toBeTrue();
});

test('a gamemaster of one game cannot touch another game\'s roster', function () {
    $theirs = Game::factory()->create();
    $other = Game::factory()->create();

    $gamemaster = gamemasterOf($theirs);
    $target = GameSeat::factory()->for($other)->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.retire', ['game' => $other, 'seat' => $target]))
        ->assertForbidden();

    expect($target->fresh()?->is_active)->toBeTrue();
});

test('the roster reflects a change made through the gamemaster screen', function () {
    /*
     * End to end rather than endpoint by endpoint: the flags the screen renders come from the same
     * controller that refuses the requests, so a change that updated one without the other would
     * leave the page offering a control that 403s.
     */
    $game = Game::factory()->create();
    /* Named so the roster's active-then-alphabetical ordering is deterministic. */
    $gamemaster = gamemasterOf($game, ['name' => 'Ada Gamemaster']);
    $player = User::factory()->create(['name' => 'Grace Hopper']);

    $seat = GameSeat::factory()->for($game)->for($player)->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seats.role.update', ['game' => $game, 'seat' => $seat]), [
            'role' => GameRole::Gamemaster->value,
        ]);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            ->has('seats', 2)
            ->has('seats.1', fn (Assert $row) => $row
                ->where('user_name', 'Grace Hopper')
                ->where('role', 'gamemaster')
                /* Newly a gamemaster, so the picker is gone and only retirement is left. */
                ->where('can_demote', false)
                ->where('can_retire', true)
                ->etc(),
            ),
        );
});

test('the gamemaster seat controller never consults the application role', function () {
    /*
     * The mirror of the assertion `GameRoleSeparationTest` makes about `Admin\GameSeatController`.
     * Game authorisation reads a seat and nothing else — a controller that started consulting
     * `users.role` to decide who may demote whom would be the merge `.ai/rules/roles.md` forbids,
     * even if it happened to agree with the seat check on the day it was written.
     */
    $code = executableSourceOf(GameSeatController::class);

    expect($code)->not->toContain('isAdmin');
    expect($code)->not->toContain('UserRole');
    expect($code)->not->toContain('users.role');

    /* The positive control: the stripping left the actual code behind. */
    expect($code)->toContain('GameRole::Gamemaster');
});
