<?php

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Generation\ClusterGenerator;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Planet;
use App\Models\Star;
use App\Models\Stellium;
use App\Models\User;
use Illuminate\Support\Facades\Route;
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

    /*
     * `{stage}` binds to the enum, so this costs no code — but it is worth knowing it holds. The stage
     * named here must be one that will never exist: a real-but-locked stage is a **403**, which is a
     * different rule, and pointing this at the next stage somebody plans to add turns it into a test
     * that fails the day they add it while looking like it caught something.
     */
    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'moons']), ['seed' => 1])
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

    /* Traveler mode is off unless it was asked for, and an ordinary cluster stacks a few systems. */
    expect($run->traveler)->toBeFalse();
    expect($run->summary['occupied_hexes'])->toBeLessThan(ClusterGenerator::LOCATION_COUNT);
});

test('a gamemaster generates a traveler cluster, and no two systems share a hex', function () {
    /*
     * The end of the constraint that starts in `ClusterGenerator`: what is asked for on the form has
     * to survive as far as the rows, because the hex map reads `(x, y)` off them and nothing else.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242, traveler: true);

    $run = GenerationRun::query()->sole();

    expect($run->traveler)->toBeTrue();
    expect($run->summary['occupied_hexes'])->toBe(ClusterGenerator::LOCATION_COUNT);

    $columns = $game->locations()
        ->get()
        ->map(fn (Location $location): string => "{$location->x},{$location->y}")
        ->all();

    expect($game->locations()->count())->toBe(ClusterGenerator::LOCATION_COUNT);
    expect(array_unique($columns))->toHaveCount(ClusterGenerator::LOCATION_COUNT);

    /* And the centre hex is clear, which the origin check alone does not deliver — `(0, 0, z)`. */
    expect($game->locations()->where('x', 0)->where('y', 0)->exists())->toBeFalse();
});

test('the same seed draws a different cluster with traveler mode on', function () {
    /*
     * Two games rather than a regeneration, so the seed can be held still: the flag has to be what
     * makes the difference, not the seed the regeneration rule would force to change.
     */
    $ordinary = Game::factory()->create();
    generateStage(gamemasterOf($ordinary), $ordinary, GenerationStage::Cluster, 4242);

    $traveler = Game::factory()->create();
    generateStage(gamemasterOf($traveler), $traveler, GenerationStage::Cluster, 4242, traveler: true);

    $pointsOf = fn (Game $game): array => $game->locations()
        ->orderBy('ordinal')
        ->get()
        ->map(fn (Location $location): string => "{$location->x},{$location->y},{$location->z}")
        ->all();

    expect($pointsOf($traveler))->not->toBe($pointsOf($ordinary));
});

test('the screen carries the traveler setting, so trying another seed keeps the mode', function () {
    /*
     * The checkbox starts from this rather than from unticked, so a gamemaster regenerating a traveler
     * cluster gets another traveler cluster instead of silently dropping back to the ordinary draw.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    generateStage($gamemaster, $game, GenerationStage::Cluster, 4242, traveler: true);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('generation.stages.0', fn (Assert $stage) => $stage
                ->where('stage', 'cluster')
                ->where('traveler', true)
                ->etc(),
            )
            /* Null before a run, which the screen reads as unticked: there is nothing to inherit yet. */
            ->has('generation.stages.1', fn (Assert $stage) => $stage
                ->where('traveler', null)
                ->etc(),
            ),
        );
});

test('a traveler setting that is not a boolean is refused', function () {
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    $this->actingAs($gamemaster)
        ->post(
            route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'cluster']),
            ['seed' => 4242, 'traveler' => 'maybe'],
        )
        ->assertInvalid('traveler');

    expect($game->generationRuns()->count())->toBe(0);
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

test('accepting the stelliums unlocks the home template rather than finishing the world', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, 7);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'stelliums'])
    )->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $game->load('generationRuns');

    /*
     * The check sweeps every stage rather than naming the last one, which is exactly why adding a
     * stage makes every half-built game incomplete again. That is the intended behaviour, and it has
     * now happened twice — for the planets, and for the template.
     */
    expect($game->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::HomeStelliaTemplate);
    expect($game->generationStateFor(GenerationStage::HomeStelliaTemplate)->value)->toBe('ready');
});

test('the stages unlock one another in the order the enum declares them', function () {
    /*
     * The reordering test. Each stage opens exactly the next and nothing further, which is the whole
     * of what the declaration order buys — so this walks the chain rather than asserting any one link,
     * and would fail on a stage moved, added or dropped anywhere along it.
     */
    $game = Game::factory()->create();
    $gamemaster = gamemasterOf($game);

    foreach (GenerationStage::cases() as $position => $stage) {
        expect($game->fresh()?->load('generationRuns')->firstUnfinishedGenerationStage())->toBe($stage);

        $seeds = [4242, 7, 11, 3, 88_213, 9];

        $stage === GenerationStage::HomeStelliaTemplate
            ? generateTemplate($gamemaster, $game, $seeds[$position])
            : generateStage($gamemaster, $game, $stage, $seeds[$position]);

        $this->actingAs($gamemaster)->post(
            route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => $stage->value])
        )->assertRedirect(route('gamemaster.games.show', ['game' => $game]));
    }

    expect($game->fresh()?->load('generationRuns')->isGenerationComplete())->toBeTrue();
});

test('accepting the home stellia unlocks the planets, and does not complete the generation', function () {
    /*
     * This test has been rewritten twice as stages arrived, and it is the one the `GenerationStage`
     * docblock warns will keep changing. The assertion that matters is not which stage is last — it is
     * that accepting one opens exactly the next.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    generateStage($gamemaster, $game, GenerationStage::HomeStellia, 3);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    )->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $game->load('generationRuns');

    expect($game->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::Planets);
    expect($game->generationStateFor(GenerationStage::Planets)->value)->toBe('ready');
});

test('a gamemaster gives every star its planets', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    $response = generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $run = $game->generationRuns()->where('stage', GenerationStage::Planets)->sole();

    /* 141 stars, one to ten planets each — the summary is what the review screen reads. */
    expect(Planet::query()->count())->toBe($run->summary['planets']);
    expect($run->summary['stars'])->toBe(Star::query()->count());
    expect(array_sum($run->summary['types']))->toBe($run->summary['planets']);

    foreach (Star::query()->withCount('planets')->get() as $star) {
        expect($star->planets_count)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10);
    }
});

test('every star numbers its planets from one, without a gap', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    /*
     * The orbit is the whole of a planet's identity, so a gap in the numbering is a planet that cannot
     * be named. Checked on a handful of stars rather than all 141: the property is per star.
     */
    foreach (Star::query()->with('planets')->take(12)->get() as $star) {
        expect($star->planets->pluck('ordinal')->all())->toBe(range(1, $star->planets->count()));
    }
});

test('the planets cannot be generated until the home stellia are accepted', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    /* A home stellia run that exists but is only pending is not an accepted one. */
    generateStage($gamemaster, $game, GenerationStage::HomeStellia, 3);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213)->assertForbidden();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'planets']))
        ->assertForbidden();

    expect(Planet::query()->count())->toBe(0);
});

test('regenerating the planets replaces them and keeps the seed that was tried', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $first = Planet::query()->orderBy('id')->pluck('habitability')->all();

    generateStage($gamemaster, $game, GenerationStage::Planets, 5150);

    $second = Planet::query()->orderBy('id')->pluck('habitability')->all();

    expect($second)->not->toBe($first);

    /* Every planet belongs to the standing run: the superseded one's were thrown away, not orphaned. */
    $standing = $game->generationRuns()->standing()->where('stage', GenerationStage::Planets)->sole();

    expect(Planet::query()->where('generation_run_id', '!=', $standing->id)->count())->toBe(0);

    /* The rejected attempt survives as a row, because its seed is the record of what was tried. */
    $game->load('generationRuns');

    expect($game->generationRuns->where('stage', GenerationStage::Planets)->count())->toBe(2);
    expect($game->generationRunFor(GenerationStage::Planets)->attempt)->toBe(2);
});

test('the same seed gives the same planets, so an accepted world can be replayed', function () {
    $first = Game::factory()->create();
    generateStage(withAcceptedHomeStellia($first), $first, GenerationStage::Planets, 88_213);

    $second = Game::factory()->create();
    generateStage(withAcceptedHomeStellia($second), $second, GenerationStage::Planets, 88_213);

    $shape = fn (Game $game): array => Planet::query()
        ->join('stars', 'stars.id', '=', 'planets.star_id')
        ->join('stelliums', 'stelliums.id', '=', 'stars.stellium_id')
        ->join('locations', 'locations.id', '=', 'stelliums.location_id')
        ->where('locations.game_id', $game->getKey())
        ->orderBy('locations.ordinal')
        ->orderBy('stars.ordinal')
        ->orderBy('planets.ordinal')
        ->pluck('planets.type')
        ->all();

    expect($shape($first))->toBe($shape($second))->not->toBeEmpty();
});

test('starting over deletes the whole world and every record of it', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $response = $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]));

    $response->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    expect($game->generationRuns()->count())->toBe(0);
    expect($game->locations()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    /* The stars go with their stelliums through the cascade, without anything deleting them. */
    expect(Star::query()->count())->toBe(0);
    /* And the planets with the stars: the chain is four deep, and one delete has to reach the end. */
    expect(Planet::query()->count())->toBe(0);

    $game->load('generationRuns');

    expect($game->generationStateFor(GenerationStage::Cluster)->value)->toBe('ready');
    expect($game->generationStateFor(GenerationStage::Stelliums)->value)->toBe('locked');
    expect($game->generationStateFor(GenerationStage::Planets)->value)->toBe('locked');
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

test('a location says how many planets it has, and says nothing before they exist', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    /*
     * Null rather than zero, and for a different reason than `star_count`'s null: the stellium already
     * exists at this point, so its planet count really *is* zero, and only the absence of a run tells
     * the two apart. Reading the zero instead would happen to work today and stop working the day a
     * star can have no planets.
     */
    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.star_count', fn (int $stars): bool => $stars >= 1)
            ->where('locations.0.planet_count', null)
            ->etc(),
        );

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.planet_count', fn (int $planets): bool => $planets >= 1)
            ->etc(),
        );
});

test('every location ships the coordinates the hex map plots it from', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    /*
     * `ClusterHexMap.svelte` lays the cluster out entirely on the client: `x` and `y` choose the hex,
     * `z` is printed beside the system because the plane has nowhere else to put it, and `star_count`
     * picks the mark. None of that reaches the server, so nothing else would notice these fields
     * going missing — the screen would simply render a hundred systems stacked on the origin.
     *
     * Asserted over the whole payload rather than `locations.0`: a map is wrong if any one of its
     * hundred systems has a hole in it, and the first row is the least likely to.
     */
    $response = $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk();

    /*
     * Walked in PHP rather than through `AssertableInertia::has()` with a callback, because that form
     * scopes the callback to the **first** element only — it would report a hundred rows checked
     * while checking one.
     */
    $locations = $response->viewData('page')['props']['locations'];

    expect($locations)->toHaveCount(ClusterGenerator::LOCATION_COUNT);

    foreach ($locations as $location) {
        expect($location['x'])->toBeInt()
            ->and($location['y'])->toBeInt()
            ->and($location['z'])->toBeInt()
            ->and($location['radius'])->toBeNumeric()
            ->and($location['star_count'])->toBeGreaterThanOrEqual(1)
            ->and($location['star_count'])->toBeLessThanOrEqual(4);
    }
});

test('a location\'s planets are fetched a row at a time, not shipped with the screen', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    /* Several hundred planets do not ride along on every render: the prop is absent until asked for. */
    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('locationDetail'));

    $location = $game->locations()->orderBy('ordinal')->firstOrFail();

    /*
     * Asserted on the JSON rather than with `assertInertia`, which reads the `page` view data and so
     * only understands a full page render. A partial reload is a JSON response with no view at all.
     */
    $response = reloadLocationDetail($gamemaster, $game, $location->id)->assertOk();

    $response->assertJsonPath('props.locationDetail.id', $location->id);
    $response->assertJsonPath('props.locationDetail.ordinal', 1);
    /* Stars are named by their place in the stellium, so the first is always A. */
    $response->assertJsonPath('props.locationDetail.stars.0.label', 'A');
    $response->assertJsonPath('props.locationDetail.stars.0.planets.0.ordinal', 1);

    $response->assertJsonStructure([
        'props' => ['locationDetail' => ['stars' => [
            ['id', 'label', 'planets' => [
                /*
                 * `entities` is present and empty here: the assets stage has not run, and a world
                 * nobody is standing on ships the same shape as one somebody is.
                 */
                ['id', 'ordinal', 'type', 'type_label', 'habitability', 'fuel', 'metals', 'minerals', 'entities'],
            ]],
        ]]],
    ]);

    /* The rest of the screen stayed behind: that is the whole point of asking for one prop. */
    $response->assertJsonMissingPath('props.locations');
    $response->assertJsonMissingPath('props.seats');
});

test('one game cannot read another game\'s system through the location parameter', function () {
    /*
     * The location arrives as a query parameter rather than as a route parameter, so
     * `Route::scopeBindings()` is not doing this — the presenter scopes it to the game by hand, and
     * that is the thing worth a test of its own.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    $other = Game::factory()->create();
    generateStage(withAcceptedHomeStellia($other), $other, GenerationStage::Planets, 88_213);

    $stranger = $other->locations()->orderBy('ordinal')->firstOrFail();

    reloadLocationDetail($gamemaster, $game, $stranger->id)
        ->assertOk()
        ->assertJsonPath('props.locationDetail', null);
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
            /*
             * "Stellia", not "stelliums": the refusal names the stage by its **label**, which is the
             * Latin plural the game is played in. The enum case and its stored value stay `Stelliums`
             * — see `GenerationStage::label()` for why only one of the two is free to change.
             */
            'status' => 'The stellia stage has not been accepted yet, so this game cannot become active.',
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
     * stelliums and planets off the stars. Deleting a game is the only thing that removes an *accepted*
     * world. The whole world is generated here rather than just the cluster, so every assertion below
     * has something to be true about — a count of zero proves nothing about a table that was empty.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    expect(Planet::query()->count())->toBeGreaterThan(0);

    $survivor = Game::factory()->create();
    $survivingRun = GenerationRun::factory()->for($survivor)->create();

    $game->delete();

    expect(Location::query()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    expect(Star::query()->count())->toBe(0);
    expect(Planet::query()->count())->toBe(0);
    expect(GenerationRun::query()->whereKey($survivingRun->getKey())->exists())->toBeTrue();
});
