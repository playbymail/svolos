<?php

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\HomeStellium;
use App\Models\Location;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Giving every player somewhere to begin
|--------------------------------------------------------------------------
|
| The fourth generation stage, and it is a **stage** rather than a screen of its own for the reason the
| whole subsystem is built that way: run from a seed, review, accept or try again, and lose it all when
| somebody starts over. That decision is what this file is really testing — it adds no routes, so
| everything about *when* it may run is already covered by `GenerationTest`, and what is left is what
| makes this stage different from the ones around it.
|
| It is no longer last. The planets stage follows it, because a home system is copied from the game's
| template rather than drawn, and which systems are homes is what this stage settles.
|
| Three things are different, and each has tests here:
|
| - the stream is seeded with the seed **and the attempt**, so generating again without touching the
|   seed gives a different arrangement — and the "choose a different seed" rule is switched off;
| - the minimum separation is an editable input, counted in **hexes**, and a value the cluster cannot
|   satisfy is a message on that field rather than a 500;
| - it reads the **roster**, so it places active players and nobody else — and a player seated after
|   the stage was accepted has no home, which is the hole `gameStatusRules()` covers.
|
*/

/**
 * Generate the home stellia, optionally at a separation other than the default.
 */
function generateHomes(User $gamemaster, Game $game, int $seed, ?int $separation = null, bool $inHexes = false): TestResponse
{
    $payload = ['seed' => $seed];

    if ($separation !== null) {
        $payload['minimum_separation'] = $separation;
    }

    /* Posted only when ticked, because that is all an unticked checkbox does. */
    if ($inHexes) {
        $payload['separation_in_hexes'] = '1';
    }

    return test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'home_stellia']),
        $payload,
    );
}

test('the stage is locked until the home template has been accepted', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    seatPlayers($game, 3);

    expect($game->load('generationRuns')->generationStateFor(GenerationStage::HomeStellia)->value)
        ->toBe('locked');

    generateHomes($gamemaster, $game, 4242)->assertForbidden();

    expect(HomeStellium::query()->count())->toBe(0);
});

test('a gamemaster places one home per player, single-starred and well clear of each other', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    $seats = seatPlayers($game, 4);

    generateHomes($gamemaster, $game, 4242)
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $homes = HomeStellium::query()->with('location.stellium')->get();

    expect($homes)->toHaveCount(4);
    expect($homes->pluck('game_seat_id')->sort()->values()->all())
        ->toBe(collect($seats)->pluck('id')->sort()->values()->all());

    /* Every home stands at one star: a player beginning in a quadruple starts with four systems' worth. */
    foreach ($homes as $home) {
        expect($home->location->stellium?->stars()->count())->toBe(1);
    }

    /*
     * And no two are closer than the default minimum, which is a **straight-line distance** through
     * all three dimensions unless the gamemaster ticks the box for hexes.
     */
    foreach ($homes as $home) {
        foreach ($homes as $other) {
            if ($home->id === $other->id) {
                continue;
            }

            expect(sqrt($home->location->coordinates()->squaredDistanceTo($other->location->coordinates())))
                ->toBeGreaterThanOrEqual(5);
        }
    }

    /* Unticked is the default, and the run records the measure it was actually generated under. */
    expect($game->generationRuns()->where('stage', GenerationStage::HomeStellia)->sole()->separation_in_hexes)
        ->toBeFalse();
});

test('ticking the box counts the separation in hexes instead', function () {
    /*
     * The two measures are different questions rather than two scales of one, so this asserts the
     * *hex* rule holds — which a Euclidean arrangement need not satisfy, since two systems sharing a
     * column are well apart through space and zero apart on the map.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 4);

    generateHomes($gamemaster, $game, 4242, 6, inHexes: true)->assertSessionHasNoErrors();

    $run = $game->generationRuns()->where('stage', GenerationStage::HomeStellia)->sole();

    expect($run->separation_in_hexes)->toBeTrue();
    expect($run->summary['realised_separation'])->toBeGreaterThanOrEqual(6);

    $homes = HomeStellium::query()->with('location')->get();

    foreach ($homes as $home) {
        foreach ($homes as $other) {
            if ($home->id === $other->id) {
                continue;
            }

            expect($home->location->hexDistanceTo($other->location))->toBeGreaterThanOrEqual(6);
        }
    }
});

test('the same seed and separation give different arrangements under the two measures', function () {
    /*
     * The checkbox has to actually change the world, not just be recorded — the clearest statement
     * that this is a choice and not a label.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 4);

    generateHomes($gamemaster, $game, 4242, 5);
    $euclidean = HomeStellium::query()->orderBy('game_seat_id')->pluck('location_id')->all();

    generateHomes($gamemaster, $game, 4242, 5, inHexes: true);
    $hexes = HomeStellium::query()->orderBy('game_seat_id')->pluck('location_id')->all();

    expect($hexes)->not->toBe($euclidean);
});

test('the summary reports what was asked for and what was achieved', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 3);

    generateHomes($gamemaster, $game, 4242, 6);

    $run = $game->generationRuns()->where('stage', GenerationStage::HomeStellia)->sole();

    expect($run->minimum_separation)->toBe(6);
    expect($run->separation_in_hexes)->toBeFalse();
    expect($run->summary['players'])->toBe(3);
    expect($run->summary['minimum_separation'])->toBe(6);

    /*
     * Measured from the arrangement rather than echoed back from the input, which is the whole reason
     * both numbers are in the summary: the realised one says how much room was actually left.
     */
    expect($run->summary['realised_separation'])->toBeGreaterThanOrEqual(6);
    expect($run->summary['candidates'])->toBeGreaterThan(0);
});

test('generating again with the same seed is allowed, and lands somewhere else', function () {
    /*
     * **The gesture this stage exists for.** Every other stage refuses a repeated seed, because the
     * same seed would redraw the same thing; here the attempt is folded into the stream, so it would
     * not — and the rule is switched off in `GenerationRunRequest` to match.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 4);

    generateHomes($gamemaster, $game, 4242);
    $first = HomeStellium::query()->orderBy('game_seat_id')->pluck('location_id')->all();

    generateHomes($gamemaster, $game, 4242)->assertSessionHasNoErrors();
    $second = HomeStellium::query()->orderBy('game_seat_id')->pluck('location_id')->all();

    expect($second)->not->toBe($first);

    /* The superseded run kept its seed and its rows went with it — one arrangement at a time. */
    expect(HomeStellium::query()->count())->toBe(4);
    expect($game->generationRuns()->where('stage', GenerationStage::HomeStellia)->count())->toBe(2);
    expect($game->load('generationRuns')->generationRunFor(GenerationStage::HomeStellia)?->attempt)->toBe(2);
});

test('a separation this cluster cannot satisfy is a message on that field, not an error page', function () {
    /*
     * Reachable from the screen in ordinary use, unlike every other generator failure — so it is a
     * rejected field naming the dial that has to move, and nothing is written.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 6);

    generateHomes($gamemaster, $game, 4242, 25, inHexes: true)
        ->assertInvalid(['minimum_separation' => 'at least 25 hexes apart']);

    expect(HomeStellium::query()->count())->toBe(0);
    expect($game->generationRuns()->where('stage', GenerationStage::HomeStellia)->count())->toBe(0);
});

test('a separation wider than the cluster is refused before a generator runs', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 2);

    generateHomes($gamemaster, $game, 4242, 31)->assertInvalid(['minimum_separation']);
});

test('gamemasters and retired seats are not given homes', function () {
    /*
     * A gamemaster runs the game rather than playing it, and a retired seat is somebody who has left —
     * a starting system for either would put a faction on the map that nobody is playing.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    [$playing] = seatPlayers($game, 1);

    $departed = GameSeat::factory()->for($game)->for(User::factory())->create();
    $departed->is_active = false;
    $departed->save();

    generateHomes($gamemaster, $game, 4242);

    expect(HomeStellium::query()->pluck('game_seat_id')->all())->toBe([$playing->id]);
});

test('a game with no players generates an empty arrangement and is still acceptable', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    generateHomes($gamemaster, $game, 4242)->assertRedirect();

    expect(HomeStellium::query()->count())->toBe(0);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    )->assertRedirect();

    /*
     * An empty arrangement is a real one: the stage is accepted and the planets open behind it, rather
     * than the game stalling on a stage that had nothing to do.
     */
    expect($game->load('generationRuns')->generationStateFor(GenerationStage::HomeStellia)->value)
        ->toBe('accepted');
    expect($game->generationStateFor(GenerationStage::Planets)->value)->toBe('ready');
});

test('the planets are drawn over the homes, and the world is finished after them', function () {
    /*
     * This stage used to be the one that finished a world, and it is no longer: the planets follow it,
     * because a home system is copied from the template rather than drawn and this is what decides
     * which systems those are. Asserted from here as well as from `GenerationTest` because the order
     * of these two in particular is the whole reason the workflow was rearranged.
     *
     * The planets no longer finish it either — the units stage puts everybody on the board after
     * them — so the chain is walked one link further rather than stopping where it used to. What is
     * being pinned here is still the ordering of *these two*; the last link is here so that the test
     * says what "complete" means today instead of asserting it of a stage that is no longer last.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 2);
    generateHomes($gamemaster, $game, 4242);

    expect($game->load('generationRuns')->isGenerationComplete())->toBeFalse();

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    )->assertRedirect();

    expect($game->load('generationRuns')->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::Planets);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'planets'])
    )->assertRedirect();

    expect($game->load('generationRuns')->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::Assets);

    generateStage($gamemaster, $game, GenerationStage::Assets, 9);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'assets'])
    )->assertRedirect();

    expect($game->load('generationRuns')->isGenerationComplete())->toBeTrue();
    expect($game->firstUnfinishedGenerationStage())->toBeNull();
});

test('starting the generation over takes every home with it, and leaves the roster alone', function () {
    /*
     * The requirement the whole table shape exists for, asserted through the endpoint rather than by
     * deleting rows: the homes hang off the runs, so `RestartGeneration` deleting those cascades to
     * them and there is no code anybody has to remember to write.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    seatPlayers($game, 3);
    generateHomes($gamemaster, $game, 4242);

    expect(HomeStellium::query()->count())->toBe(3);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.restart', ['game' => $game])
    )->assertRedirect();

    expect(HomeStellium::query()->count())->toBe(0);
    expect(Location::query()->count())->toBe(0);

    /* Seats are roster, not world: losing a home is not losing your place at the game. */
    expect($game->seats()->count())->toBe(4);
});

test('the screen carries the arrangement, on the map and on the roster', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    $seats = seatPlayers($game, 2);
    generateHomes($gamemaster, $game, 4242, 7, inHexes: true);

    $home = HomeStellium::query()->with('location')->where('game_seat_id', $seats[0]->id)->sole();

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            /*
             * The form starts from the separation the last attempt used, rather than the default —
             * and from its **unit**, since the number means nothing without it.
             */
            ->where('generation.stages.3.stage', 'home_stellia')
            ->where('generation.stages.3.minimum_separation', 7)
            ->where('generation.stages.3.separation_in_hexes', true)
            /*
             * And from the run's **own** seed, not a fresh random one. Every other stage suggests a
             * new number because it would otherwise redraw the same thing; here the attempt is in the
             * stream, so suggesting a different seed would silently change the world the arrangement
             * is drawn from — and would contradict the form, which says the same seed is fine.
             */
            ->where('generation.stages.3.suggested_seed', 4242)
            ->has('locations', 100)
            ->has('seats')
        );

    $payload = $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->viewData('page')['props'];

    $marked = collect($payload['locations'])->firstWhere('id', $home->location_id);

    expect($marked['home_seat_id'])->toBe($seats[0]->id);
    /* The empire's name rather than the account's — see `PresentsGeneration::empireNameFor()`. */
    expect($marked['home_player_name'])->toBe($seats[0]->empireName());

    $row = collect($payload['seats'])->firstWhere('id', $seats[0]->id);

    expect($row['home']['ordinal'])->toBe($home->location->ordinal);
    expect($row['home']['x'])->toBe($home->location->x);
    expect($row['home']['y'])->toBe($home->location->y);
    expect($row['home']['z'])->toBe($home->location->z);

    /* The gamemaster's own row is never placed, so its column reads as empty rather than as missing. */
    $own = collect($payload['seats'])->firstWhere('role', GameRole::Gamemaster->value);

    expect($own['home'])->toBeNull();
});

test('a game cannot become active while a player has nowhere to begin', function () {
    /*
     * **Not the same check as "every stage accepted", and this is the case that shows why.** The stage
     * places everybody seated at the time; seating somebody afterwards leaves them with no home and no
     * way to get one short of starting the whole world over — which the run-based check cannot see.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    seatPlayers($game, 2);
    withCompletedGeneration($game);
    withPlacedHomes($game);

    /* Seated after the arrangement was accepted: generation is complete and this player has nothing. */
    $latecomer = GameSeat::factory()->for($game)->for(User::factory())->create();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertInvalid(['status' => 'One player has no home stellium yet, so this game cannot become active.']);

    expect($game->fresh()?->status)->toBe(GameStatus::Setup);

    /* Retiring them is one way out, and then the game may start. */
    $latecomer->is_active = false;
    $latecomer->save();

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('the unplaced message counts the players rather than naming one', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    withCompletedGeneration($game);
    seatPlayers($game, 3);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertInvalid(['status' => '3 players have no home stellium yet, so this game cannot become active.']);
});

test('the missing stage is reported before the unplaced players are', function () {
    /*
     * Order, not preference: a world with no cluster has nowhere to put a home, so telling somebody
     * about unplaced players before they have generated anything would name the symptom.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    seatPlayers($game, 2);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertInvalid(['status' => 'The cluster stage has not been accepted yet, so this game cannot become active.']);
});

test('an administrator is refused the same game for the same reason', function () {
    /*
     * `gameStatusRules()` is shared, and the point of sharing it is that the two areas cannot drift
     * apart about when a game is playable.
     */
    $game = Game::factory()->create();
    $administrator = User::factory()->admin()->create();

    withCompletedGeneration($game);
    seatPlayers($game, 1);

    $this->actingAs($administrator)
        ->put(route('admin.games.update', ['game' => $game]), [
            'name' => $game->name,
            'short_name' => $game->short_name,
            'status' => GameStatus::Active->value,
        ])
        ->assertInvalid(['status' => 'One player has no home stellium yet, so this game cannot become active.']);
});

test('the administrator sees where players begin, read only', function () {
    $game = Game::factory()->create();
    $administrator = User::factory()->admin()->create();

    seatPlayers($game, 1);
    withCompletedGeneration($game);
    withPlacedHomes($game);

    $seat = $game->activeSeats()->where('role', GameRole::Player)->sole();

    $payload = $this->actingAs($administrator)
        ->get(route('admin.games.show', ['game' => $game]))
        ->viewData('page')['props'];

    $row = collect($payload['seats'])->firstWhere('id', $seat->id);

    expect($row['home']['location_id'])->toBe($seat->homeStellium?->location_id);

    /* And no cluster ships with this screen: building the world is the gamemaster's. */
    expect($payload)->not->toHaveKey('locations');
});
