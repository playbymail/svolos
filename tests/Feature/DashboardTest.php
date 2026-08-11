<?php

use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The member games dashboard
|--------------------------------------------------------------------------
|
| Three shapes are pinned here, and all three are easy to collapse into each other by accident.
|
| A section with no seats is **missing from the props**, not present and empty. A section whose
| games are all archived is a different state: it is present, because the account really is in
| those games, and the page keeps its heading and its toggle and says so in words. Tests below
| assert `missing()` for the first and `has()` for the second, on purpose.
|
| Archived games ship **in** the payload, flagged with `is_archived`, so the toggle that reveals
| them is client-side state and costs no round trip. A change that filtered them out on the server
| would still pass a test that only counted the unarchived ones, so the counts here include them.
|
| Only active seats count, and only this account's. A retired seat's row still exists — seats are
| never deleted — so "the game is absent" has to be asserted against a roster that still has the
| row in it.
|
*/

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});

test('an account with no seats gets neither section', function () {
    $member = User::factory()->create();

    /* A game exists, and somebody else is in it — neither fact belongs to this account. */
    GameSeat::factory()->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('gamemasterGames')
            ->missing('playerGames'),
        );
});

test('an administrator with no seats gets the same empty dashboard as a member', function () {
    /*
     * There is no role branching on this screen. An administrator holds no seat merely by being an
     * administrator, and this is the screen an impersonation lands on, so it has to be the same one.
     */
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('gamemasterGames')
            ->missing('playerGames'),
        );
});

test('the sections split by game role and each is ordered by short name', function () {
    $member = User::factory()->create();

    $zulu = Game::factory()->active()->create(['name' => 'Zulu Run', 'short_name' => 'ZULU']);
    $alpha = Game::factory()->active()->create(['name' => 'Alpha Run', 'short_name' => 'ALPHA']);
    $mike = Game::factory()->paused()->create(['name' => 'Mike Run', 'short_name' => 'MIKE']);
    $bravo = Game::factory()->create(['name' => 'Bravo Run', 'short_name' => 'BRAVO']);

    GameSeat::factory()->gamemaster()->for($zulu)->for($member)->create();
    GameSeat::factory()->gamemaster()->for($alpha)->for($member)->create();
    GameSeat::factory()->for($mike)->for($member)->create();
    GameSeat::factory()->for($bravo)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('gamemasterGames', 2)
            ->where('gamemasterGames.0.short_name', 'ALPHA')
            ->where('gamemasterGames.1.short_name', 'ZULU')
            ->has('playerGames', 2)
            ->where('playerGames.0.short_name', 'BRAVO')
            ->where('playerGames.1.short_name', 'MIKE'),
        );
});

test('each game carries exactly the fields the dashboard presents', function () {
    $member = User::factory()->create();
    $game = Game::factory()->paused()->create(['name' => 'The Long Retreat', 'short_name' => 'RETREAT']);

    GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('playerGames', 1)
            /*
             * No `->etc()`: the whole key set is named, so nothing about the game — or about the
             * roster of accounts sitting at it — can start arriving here unnoticed.
             */
            ->has('playerGames.0', fn (Assert $presented) => $presented
                ->where('id', $game->id)
                ->where('name', 'The Long Retreat')
                ->where('short_name', 'RETREAT')
                ->where('status', 'paused')
                ->where('status_label', 'Paused')
                ->where('is_archived', false),
            ),
        );
});

test('an account holding only a gamemaster seat has no player section at all', function () {
    $member = User::factory()->create();
    $game = Game::factory()->active()->create(['short_name' => 'SOLO']);

    GameSeat::factory()->gamemaster()->for($game)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('gamemasterGames', 1)
            ->where('gamemasterGames.0.short_name', 'SOLO')
            /* Absent, not present-and-empty: the page renders a heading for any section it is given. */
            ->missing('playerGames'),
        );
});

test('an account holding only a player seat has no gamemaster section at all', function () {
    $member = User::factory()->create();
    $game = Game::factory()->active()->create(['short_name' => 'SEAT']);

    GameSeat::factory()->for($game)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('playerGames', 1)
            ->missing('gamemasterGames'),
        );
});

test('archived games are shipped in the payload flagged and interleaved by short name', function () {
    /*
     * The flag is what makes the toggle instant, so the assertion is that the archived game is
     * *present* — filtering it out on the server would be the regression, and it would leave the
     * remaining two games looking perfectly correct.
     */
    $member = User::factory()->create();

    $alpha = Game::factory()->active()->create(['short_name' => 'ALPHA']);
    $mike = Game::factory()->archived()->create(['short_name' => 'MIKE']);
    $zulu = Game::factory()->completed()->create(['short_name' => 'ZULU']);

    foreach ([$alpha, $mike, $zulu] as $game) {
        GameSeat::factory()->for($game)->for($member)->create();
    }

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('playerGames', 3)
            /* Interleaved in the one ordering rather than pushed to the end: the toggle reveals in place. */
            ->where('playerGames.0.short_name', 'ALPHA')
            ->where('playerGames.0.is_archived', false)
            ->where('playerGames.1.short_name', 'MIKE')
            ->where('playerGames.1.is_archived', true)
            ->where('playerGames.1.status', 'archived')
            ->where('playerGames.2.short_name', 'ZULU')
            ->where('playerGames.2.is_archived', false),
        );
});

test('a section whose games are all archived is still present in the props', function () {
    /*
     * This is deliberately *not* the same state as a section with no seats. The account is in these
     * games; they are only hidden. The section therefore exists, keeps its heading and its toggle,
     * and the page says so in words instead of rendering an empty list.
     */
    $member = User::factory()->create();

    $first = Game::factory()->archived()->create(['short_name' => 'ARCH-1']);
    $second = Game::factory()->archived()->create(['short_name' => 'ARCH-2']);

    GameSeat::factory()->gamemaster()->for($first)->for($member)->create();
    GameSeat::factory()->gamemaster()->for($second)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('gamemasterGames', 2)
            ->where('gamemasterGames.0.is_archived', true)
            ->where('gamemasterGames.1.is_archived', true)
            ->missing('playerGames'),
        );
});

test('a game the account was retired from does not appear even though its seat still exists', function () {
    $member = User::factory()->create();

    $current = Game::factory()->active()->create(['short_name' => 'CURRENT']);
    $left = Game::factory()->active()->create(['short_name' => 'LEFT']);

    GameSeat::factory()->for($current)->for($member)->create();
    $retired = GameSeat::factory()->retired()->gamemaster()->for($left)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('playerGames', 1)
            ->where('playerGames.0.short_name', 'CURRENT')
            /* The retired seat was a gamemaster one, so a leak would show up as a whole section. */
            ->missing('gamemasterGames'),
        );

    /* The row is still there — seats are retired, never deleted. */
    expect($retired->fresh()?->is_active)->toBeFalse();
});

test('another account\'s games never appear', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();

    $mine = Game::factory()->active()->create(['short_name' => 'MINE']);
    $theirs = Game::factory()->active()->create(['short_name' => 'THEIRS']);

    GameSeat::factory()->for($mine)->for($member)->create();
    GameSeat::factory()->gamemaster()->for($theirs)->for($other)->create();
    /* The other account is also in my game, which must not add a row to my dashboard either. */
    GameSeat::factory()->for($mine)->for($other)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('playerGames', 1)
            ->where('playerGames.0.short_name', 'MINE')
            ->missing('gamemasterGames'),
        );
});

test('the games are eager loaded rather than fetched one per seat', function () {
    $member = User::factory()->create();

    foreach (range(1, 5) as $index) {
        GameSeat::factory()
            ->for(Game::factory()->active()->create(['short_name' => 'GAME-'.$index]))
            ->for($member)
            ->create();
    }

    DB::enableQueryLog();

    $this->actingAs($member)->get(route('dashboard'))->assertOk();

    $gameQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], '"games"'))
        ->count();

    DB::disableQueryLog();

    /* One `with('game')` load for the whole roster, however many seats it holds. */
    expect($gameQueries)->toBe(1);
});
