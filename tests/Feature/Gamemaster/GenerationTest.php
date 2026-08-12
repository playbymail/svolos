<?php

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Generation\ClusterGenerator;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Star;
use App\Models\Stellium;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Generating a game's world
|--------------------------------------------------------------------------
|
| The gamemaster builds the world one stage at a time, from a seed: generate, look at it, then accept
| it or try another seed. Accepting unlocks the next stage. The rules that matter are all about *when*
| a stage may run, and every one of them is a 403 rather than a validation message — there is no field
| behind "the cluster has not been accepted yet", and no seed a gamemaster could type that would make
| it allowed. The one thing that *is* a message is the seed, which is a field: it must be in range,
| and it must differ from the seed already on a pending run, because the same seed redraws the same
| thing and a button that appears to do nothing reads as broken.
|
| The tests below drive the real generators rather than factories wherever the output is the subject,
| so the cluster in them is one the application would really produce.
|
*/

/**
 * Generate a stage as a gamemaster, and hand back the response.
 */
function generateStage(User $gamemaster, Game $game, GenerationStage $stage, int $seed): TestResponse
{
    return test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => $stage->value]),
        ['seed' => $seed],
    );
}

/**
 * Take a game as far as an accepted cluster, and hand back its gamemaster.
 */
function withAcceptedCluster(Game $game, int $seed = 4242): User
{
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster'])
    );

    return $gamemaster;
}

test('a guest is redirected to login from every generation route', function () {
    $game = Game::factory()->create();

    $this->post(route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'cluster']), ['seed' => 1])
        ->assertRedirect(route('login'));
    $this->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster']))
        ->assertRedirect(route('login'));
    $this->post(route('gamemaster.games.generation.restart', ['game' => $game]))
        ->assertRedirect(route('login'));

    expect(Location::query()->count())->toBe(0);
});

test('a player at the game cannot generate anything', function () {
    /*
     * The gate is the same one the rest of the area answers to: a seat at the game is not enough, the
     * role has to be right. Asserted with the write as well as the status, because a 403 that still
     * wrote would be the failure worth catching.
     */
    $game = Game::factory()->create();
    $player = User::factory()->create();

    GameSeat::factory()->for($game)->for($player)->create();

    $this->actingAs($player)
        ->post(route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'cluster']), ['seed' => 1])
        ->assertForbidden();

    expect(Location::query()->count())->toBe(0);
    expect(GenerationRun::query()->count())->toBe(0);
});

test('an administrator holding no seat cannot generate, and has no admin route that can', function () {
    /*
     * Generation is the gamemaster's, which is a decision rather than an oversight: it is part of
     * running a game. The administrator's screen shows the same summary read-only, and the sweep is
     * what says there is no `admin.games.generation.*` hiding anywhere.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)
        ->post(route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'cluster']), ['seed' => 1])
        ->assertForbidden();

    $adminGenerationRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'admin.') && str_contains($name, 'generation'));

    expect($adminGenerationRoutes)->toBeEmpty();
});

test('an unknown stage is a 404 rather than a route that does nothing', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    /* `{stage}` binds to the enum, so this costs no code — but it is worth knowing it holds. */
    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'planets']), ['seed' => 1])
        ->assertNotFound();
});

test('a gamemaster generates a cluster of a hundred locations from a seed', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run']);
    $gamemaster = gamemasterOf($game);

    $response = generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Cluster generated from seed 4242. Review it, then accept or try another seed.',
    ]);

    expect($game->locations()->count())->toBe(ClusterGenerator::LOCATION_COUNT);

    $run = GenerationRun::query()->sole();

    expect($run->stage)->toBe(GenerationStage::Cluster);
    expect($run->seed)->toBe(4242);
    expect($run->attempt)->toBe(1);
    expect($run->isPending())->toBeTrue();
    expect($run->summary['locations'] ?? null)->toBe(ClusterGenerator::LOCATION_COUNT);
});

test('the stored cluster is exactly what the generator produces from the stored seed', function () {
    /*
     * **The test the whole design exists for.** Reproducing a game means reading the seed off its
     * accepted run and replaying it, so the rows and the replay have to agree down to the ordering.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);

    $stored = $game->locations()->get()
        ->map(fn (Location $location): array => [$location->x, $location->y, $location->z])
        ->all();

    $replayed = array_map(
        fn ($coordinates): array => [$coordinates->x, $coordinates->y, $coordinates->z],
        (new ClusterGenerator)->generate(4242)->coordinates,
    );

    expect($stored)->toBe($replayed);

    /* And the ordinals number that order, from one. */
    expect($game->locations()->pluck('ordinal')->all())
        ->toBe(range(1, ClusterGenerator::LOCATION_COUNT));
});

test('regenerating replaces the cluster, supersedes the run and keeps its seed', function () {
    /*
     * The attempt survives, its output does not. That asymmetry is the point of storing runs: the
     * seeds a gamemaster rejected are the record of what was tried, while only one cluster can be the
     * game's at a time.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);
    $first = $game->locations()->pluck('x')->all();

    generateStage($gamemaster, $game, GenerationStage::Cluster, 99)->assertSessionHasNoErrors();

    expect($game->locations()->count())->toBe(ClusterGenerator::LOCATION_COUNT);
    expect($game->locations()->pluck('x')->all())->not->toBe($first);

    $runs = $game->generationRuns()->get();

    expect($runs)->toHaveCount(2);
    expect($runs[0]->seed)->toBe(4242);
    expect($runs[0]->superseded_at)->not->toBeNull();
    expect($runs[0]->locations()->count())->toBe(0);
    expect($runs[1]->seed)->toBe(99);
    expect($runs[1]->attempt)->toBe(2);
    expect($runs[1]->locations()->count())->toBe(ClusterGenerator::LOCATION_COUNT);
});

test('regenerating with the same seed is refused, because it would draw the same thing', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242)
        ->assertSessionHasErrors(['seed' => 'Choose a seed other than the one that produced this.']);

    /* Still one run, still pending: the refusal happened before anything was written. */
    expect($game->generationRuns()->count())->toBe(1);
    expect($game->locations()->count())->toBe(ClusterGenerator::LOCATION_COUNT);
});

test('the first run of a stage may use any seed, including one another stage used', function () {
    /*
     * The must-differ rule exists only while there is a pending run to differ from. A stellium stage
     * starting from the seed the cluster used is not a mistake — they are different generators.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game, 4242);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 4242)->assertSessionHasNoErrors();

    expect($game->stelliums()->count())->toBe(ClusterGenerator::LOCATION_COUNT);
});

test('a seed outside the engine range is refused', function (mixed $seed) {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, $seed)->assertSessionHasErrors('seed');

    expect($game->generationRuns()->count())->toBe(0);
    expect($game->locations()->count())->toBe(0);
})->with([
    'negative' => -1,
    'past the 32-bit ceiling' => 4294967296,
]);

test('accepting a cluster freezes it and unlocks the stelliums', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);

    $response = $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster'])
    );

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Cluster accepted.']);

    $game->load('generationRuns');

    expect($game->hasAcceptedGeneration(GenerationStage::Cluster))->toBeTrue();
    expect($game->generationStateFor(GenerationStage::Stelliums)->value)->toBe('ready');
});

test('an accepted stage cannot be regenerated past', function () {
    /*
     * A 403 rather than a message: no seed would make it allowed. The stelliums stand on the cluster,
     * so redrawing one underneath the other is not something to warn about — it is something the only
     * deliberate reset does, and says so.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    $before = $game->locations()->pluck('x')->all();

    generateStage($gamemaster, $game, GenerationStage::Cluster, 777)->assertForbidden();

    expect($game->locations()->pluck('x')->all())->toBe($before);
    expect($game->generationRuns()->count())->toBe(1);
});

test('the stelliums cannot be generated before the cluster is accepted', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    /* Locked with nothing generated at all... */
    generateStage($gamemaster, $game, GenerationStage::Stelliums, 1)->assertForbidden();

    /* ...and still locked while the cluster is only *pending*. */
    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);
    generateStage($gamemaster, $game, GenerationStage::Stelliums, 1)->assertForbidden();

    expect(Stellium::query()->count())->toBe(0);
});

test('accepting a stage that has nothing pending is refused', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster']))
        ->assertForbidden();

    /* And an already accepted stage cannot be accepted twice. */
    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);
    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster']));

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'cluster']))
        ->assertForbidden();
});

test('the stelliums put one group of stars at every location, in the advertised mix', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7)->assertSessionHasNoErrors();

    expect($game->stelliums()->count())->toBe(ClusterGenerator::LOCATION_COUNT);

    /* Every location has exactly one, and every stellium has between one and four stars. */
    foreach ($game->locations()->with('stellium.stars')->get() as $location) {
        expect($location->stellium)->not->toBeNull();
        expect($location->stellium?->stars)->toHaveCount(
            $location->stellium?->stars->count() ?? 0
        );
    }

    $sizes = Stellium::query()->withCount('stars')->get()
        ->countBy(fn (Stellium $stellium): int => (int) $stellium->stars_count)
        ->sortKeys()
        ->all();

    expect($sizes)->toBe([1 => 70, 2 => 20, 3 => 9, 4 => 1]);
    expect(Star::query()->count())->toBe(141);

    /* Stars are numbered from one inside their own stellium. */
    $stellium = Stellium::query()->withCount('stars')->orderByDesc('stars_count')->first();

    expect($stellium?->stars->pluck('ordinal')->all())->toBe(range(1, 4));
});

test('accepting the stelliums completes the generation', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'stelliums'])
    )->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $game->load('generationRuns');

    expect($game->isGenerationComplete())->toBeTrue();
    expect($game->firstUnfinishedGenerationStage())->toBeNull();
});

test('starting over deletes the whole world and every record of it', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7);

    $response = $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]));

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($game->generationRuns()->count())->toBe(0);
    expect($game->locations()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    /* The stars go with their stelliums through the cascade, without anything deleting them. */
    expect(Star::query()->count())->toBe(0);

    $game->load('generationRuns');

    expect($game->generationStateFor(GenerationStage::Cluster)->value)->toBe('ready');
    expect($game->generationStateFor(GenerationStage::Stelliums)->value)->toBe('locked');
});

test('starting over is refused when there is nothing to throw away', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]))
        ->assertForbidden();
});

test('nothing can be generated once the game has left setup', function () {
    /*
     * Generation belongs to setup, whatever a stage's own state says. A game already being played is
     * not somewhere a new cluster can appear underneath the people playing it.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    $game->status = GameStatus::Paused;
    $game->save();

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7)->assertForbidden();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]))
        ->assertForbidden();

    expect(Stellium::query()->count())->toBe(0);
});

test('the base seed closes once anything has been generated, and opens again after starting over', function () {
    /*
     * The game's own seed is what a first run starts from. Once a generator has drawn from it, editing
     * it would change nothing that exists — so the seed form closes, and the payload says so with the
     * same flag the server enforces.
     */
    $game = Game::factory()->create(['seed' => 111]);
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page->where('game.can_change_seed', true)->etc());

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);

    /*
     * The reason matters as much as the flag. This game is **still in setup** — it is locked because
     * its world has been generated — so a screen that inferred the reason from the status would tell
     * the gamemaster the game had left setup, which is both wrong and unactionable. It was, until a
     * walk through the browser showed it saying exactly that.
     */
    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.can_change_seed', false)
            ->where('game.status', 'setup')
            ->where('game.seed_lock_reason', 'This seed has already been generated from. Start the generation over to change it.')
            ->etc(),
        );

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seed.update', ['game' => $game]), ['seed' => 222])
        ->assertSessionHasErrors([
            'seed' => 'This seed has already been generated from. Start the generation over to change it.',
        ]);

    expect($game->fresh()?->seed)->toBe(111);

    $this->actingAs($gamemaster)->post(route('gamemaster.games.generation.restart', ['game' => $game]));

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.seed.update', ['game' => $game]), ['seed' => 222])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->seed)->toBe(222);
});

test('the screen carries every stage, its seed and the attempts that were dropped', function () {
    $game = Game::factory()->create(['seed' => 555]);
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242);
    generateStage($gamemaster, $game, GenerationStage::Cluster, 99);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            ->where('generation.is_complete', false)
            ->where('generation.can_generate', true)
            ->where('generation.can_start_over', true)
            ->has('generation.stages', count(GenerationStage::cases()))
            ->has('generation.stages.0', fn (Assert $stage) => $stage
                ->where('stage', 'cluster')
                ->where('state', 'review')
                ->where('seed', 99)
                ->where('attempt', 2)
                /* The seed that was regenerated past is all that is left of that attempt. */
                ->has('history', 1)
                ->where('history.0.seed', 4242)
                ->where('history.0.attempt', 1)
                ->has('suggested_seed')
                ->etc(),
            )
            ->has('generation.stages.1', fn (Assert $stage) => $stage
                ->where('stage', 'stelliums')
                ->where('state', 'locked')
                ->where('seed', null)
                ->etc(),
            )
            ->has('locations', ClusterGenerator::LOCATION_COUNT)
            ->has('locations.0', fn (Assert $location) => $location
                ->where('ordinal', 1)
                /* Null rather than zero: the stelliums have not been decided, not decided to be empty. */
                ->where('star_count', null)
                ->etc(),
            ),
        );
});

test('the administrator sees the same generation summary, read only', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game, 4242);
    $admin = User::factory()->admin()->create();

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7);

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('generation.stages', count(GenerationStage::cases()))
            ->where('generation.stages.0.state', 'accepted')
            ->where('generation.stages.0.seed', 4242)
            ->where('generation.stages.1.state', 'review')
            ->where('generation.stages.1.seed', 7)
            /* No suggested seeds: the screen it feeds has no control to put them in. */
            ->missing('generation.stages.0.suggested_seed')
            ->etc(),
        );
});

test('a game cannot become active until its world has been generated', function () {
    /*
     * A validation message rather than a 403, and the same one in both areas: the value is well formed
     * and the requester may post it, the game is simply not ready. The message names the stage that is
     * missing, because "finish generating" is not something anybody can act on.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertSessionHasErrors([
            'status' => 'The cluster stage has not been accepted yet, so this game cannot become active.',
        ]);

    /* Half-way there names the stage that is left, not the one already done. */
    withAcceptedCluster($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertSessionHasErrors([
            'status' => 'The stelliums stage has not been accepted yet, so this game cannot become active.',
        ]);

    expect($game->fresh()?->status)->toBe(GameStatus::Setup);
});

test('a fully generated game may become active, and an ungenerated one may still be archived', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    /* Archiving a half-built game is ordinary housekeeping, so only `active` is gated. */
    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Archived->value])
        ->assertSessionHasNoErrors();

    withCompletedGeneration($game);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.games.update', ['game' => $game]), ['status' => GameStatus::Active->value])
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('an administrator is held to the same rule from their own screen', function () {
    $game = Game::factory()->create(['name' => 'Alpha Run', 'short_name' => 'ALPHA']);
    $admin = User::factory()->admin()->create();

    $payload = ['name' => 'Alpha Run', 'short_name' => 'ALPHA', 'status' => GameStatus::Active->value];

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), $payload)
        ->assertSessionHasErrors('status');

    withCompletedGeneration($game);

    $this->actingAs($admin)
        ->put(route('admin.games.update', ['game' => $game]), $payload)
        ->assertSessionHasNoErrors();

    expect($game->fresh()?->status)->toBe(GameStatus::Active);
});

test('deleting a game takes its whole world with it', function () {
    /*
     * One cascade chain: runs and locations hang off the game, stelliums off both, stars off the
     * stelliums. Deleting a game is the only thing that removes an *accepted* world.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7);

    $survivor = Game::factory()->create();
    $survivingRun = GenerationRun::factory()->for($survivor)->create();

    $game->delete();

    expect(Location::query()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    expect(Star::query()->count())->toBe(0);
    expect(GenerationRun::query()->whereKey($survivingRun->getKey())->exists())->toBeTrue();
});
