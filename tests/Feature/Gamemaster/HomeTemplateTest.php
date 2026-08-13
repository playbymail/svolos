<?php

use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Generation\HomeTemplateGenerator;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\HomeStellium;
use App\Models\Planet;
use App\Models\Star;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| The home system every player begins in
|--------------------------------------------------------------------------
|
| The third generation stage, and the one that changed what the two after it do. It is a stage like any
| other — run it, review it, accept it, lose it when somebody starts over — so everything about *when*
| it may run is already covered by `GenerationTest` and is not repeated here.
|
| Three things make it different, and each has tests below:
|
| - it can be settled **two ways**: upload a document, or tick the box and draw one from the seed;
| - a document that is not a template is a message on the **file**, not an error page — the parser's
|   own refusals are unit-tested, so what matters here is that they reach the form;
| - the "choose a different seed" rule is **off for an upload and on for a draw**, because the seed is
|   what varies in one case and the document in the other.
|
| And one thing it produces, which is the reason the workflow was reordered at all: every player's home
| system comes out identical except in what it is worth to mine. That is the last test in the file, and
| it is the one worth keeping if any of the others go.
|
*/

test('a gamemaster draws a home template from the seed', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $run = $game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->sole();

    expect($run->seed)->toBe(4242);
    expect($run->template['planets'])->toHaveCount(9);
    expect($run->template['home_ordinal'])->toBe(3);
    /* Null names a drawn template, and it is how the screen tells the two apart. */
    expect($run->template['file'])->toBeNull();

    expect($run->summary['planets'])->toBe(9);
    expect($run->summary['home_habitability'])->toBe(HomeTemplateGenerator::HOME_HABITABILITY);
});

test('a gamemaster uploads a home template instead', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('gamemaster.games.show', ['game' => $game]));

    $run = $game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->sole();

    expect($run->template['file'])->toBe('homeworld.json');
    expect($run->template['home_ordinal'])->toBe(3);
    expect($run->template['planets'][2])
        ->toBe(['type' => 'rocky', 'habitability' => 25, 'fuel' => 5, 'metals' => 5, 'minerals' => 5]);

    /* The seed is still recorded, even though nothing was drawn from it. */
    expect($run->seed)->toBe(4242);
});

test('the toast names the document when one was uploaded, and the seed when one was not', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument())->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Home stellia template read from homeworld.json. Review it, then accept or upload another.',
    ]);

    /*
     * Saying "generated from seed 4242" about a document would read as the file having been ignored,
     * which is why the sentence branches at all — so the drawn half is asserted beside it.
     */
    generateTemplate($gamemaster, $game, 4242)->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Home stellia template generated from seed 4242. Review it, then accept or try another seed.',
    ]);
});

test('a document that is not a template is a message on the file', function () {
    /*
     * One case rather than the parser's whole catalogue — `tests/Unit/HomeTemplateTest.php` covers
     * every refusal. What this asserts is the wiring: `GenerationFailed` carries `template` as its
     * field, so the sentence lands beside the file input rather than on an error page or against the
     * seed, and the failed attempt leaves no run behind.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, UploadedFile::fake()->createWithContent('notes.json', 'not a template'))
        ->assertInvalid(['template' => 'not readable as JSON']);

    expect($game->generationRuns()->count())->toBe(2);
});

test('a template with no home world is refused before anything is written', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    $document = templateDocument([
        ['ordinal' => 1, 'type' => 'rocky', 'habitability' => 4],
        ['ordinal' => 2, 'type' => 'icy', 'habitability' => 1],
    ]);

    generateTemplate($gamemaster, $game, 4242, $document)
        ->assertInvalid(['template' => 'No planet is marked as the home world']);

    expect($game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->count())->toBe(0);
});

test('the stage cannot be run with neither a document nor the box ticked', function () {
    /*
     * The one refusal that is a rule of the form rather than of the parser: there are two ways to
     * settle a template and a gamemaster has to pick one.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'home_stellia_template']),
        ['seed' => 4242],
    )->assertInvalid(['template' => 'Upload a template document, or tick the box to generate one']);

    expect($game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->count())->toBe(0);
});

test('a file that is not a json document is refused by the form', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, UploadedFile::fake()->create('cluster.png', 4, 'image/png'))
        ->assertInvalid(['template' => 'A template is a JSON document.']);
});

test('re-uploading under the same seed is allowed, because the document is what changed', function () {
    /*
     * The "choose a different seed" rule rests on the seed deciding the outcome, which is false for an
     * upload: two documents under one seed are two templates. A gamemaster correcting a typo in the
     * file they just sent would otherwise be told to change a number that had nothing to do with it.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument())->assertSessionHasNoErrors();

    generateTemplate($gamemaster, $game, 4242, templateDocument(name: 'corrected.json'))
        ->assertSessionHasNoErrors();

    $standing = $game->generationRuns()->standing()
        ->where('stage', GenerationStage::HomeStelliaTemplate)
        ->sole();

    expect($standing->template['file'])->toBe('corrected.json');
    /* One template at a time, and the attempt still counts every time somebody asked. */
    expect($standing->attempt)->toBe(2);
    expect($game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->count())->toBe(2);
});

test('drawing over a drawn template under the same seed is refused, because nothing would change', function () {
    /* The one case that keeps the rule: the seed really is the whole of a drawn template's input. */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242)->assertSessionHasNoErrors();

    generateTemplate($gamemaster, $game, 4242)
        ->assertInvalid(['seed' => 'Choose a seed other than the one that produced this.']);
});

test('drawing over an uploaded template under the same seed is allowed', function () {
    /*
     * The case the rule gets wrong if it only looks at the request: the seed on the pending run
     * produced a *document*, so drawing under it is not repeating anything — a gamemaster who
     * uploaded a file and then decided to draw one instead would be refused for reusing a number that
     * never drew the thing they are looking at.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument())->assertSessionHasNoErrors();

    generateTemplate($gamemaster, $game, 4242)->assertSessionHasNoErrors();

    $standing = $game->generationRuns()->standing()
        ->where('stage', GenerationStage::HomeStelliaTemplate)
        ->sole();

    expect($standing->template['file'])->toBeNull();
});

test('a superseded template survives on the run that was rejected', function () {
    /*
     * A template is what the run *was*, the way a seed is — so it stays on a superseded row while the
     * rows a stage produced do not. It is what lets the screen name a document a gamemaster tried and
     * dropped.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument(name: 'first.json'));
    generateTemplate($gamemaster, $game, 4242, templateDocument(name: 'second.json'));

    $superseded = $game->generationRuns()
        ->where('stage', GenerationStage::HomeStelliaTemplate)
        ->whereNotNull('superseded_at')
        ->sole();

    expect($superseded->template['file'])->toBe('first.json');
});

test('starting over throws the template away with everything else', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]))
        ->assertRedirect();

    expect($game->generationRuns()->count())->toBe(0);
    expect($game->load('generationRuns')->generationStateFor(GenerationStage::HomeStelliaTemplate)->value)
        ->toBe('locked');
});

test('the screen carries the template stage, in its place in the order', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, 4242, templateDocument());

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('generation.stages.2.stage', 'home_stellia_template')
            ->where('generation.stages.2.state', 'review')
            ->where('generation.stages.2.summary.file', 'homeworld.json')
            ->where('generation.stages.3.stage', 'home_stellia')
            ->where('generation.stages.3.state', 'locked')
            ->where('generation.stages.4.stage', 'planets')
            ->etc(),
        );
});

test('a player at the game cannot settle its template', function () {
    $game = Game::factory()->create();
    withAcceptedStelliums($game);

    $player = User::factory()->create();
    GameSeat::factory()->for($game)->for($player)->create();

    generateTemplate($player, $game, 4242, templateDocument())->assertForbidden();

    expect($game->generationRuns()->where('stage', GenerationStage::HomeStelliaTemplate)->count())->toBe(0);
});

test('the template cannot be changed once the game has left setup', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedTemplate($game);

    $game->update(['status' => GameStatus::Paused]);

    generateTemplate($gamemaster, $game, 999, templateDocument())->assertForbidden();
});

test('every player begins in the same home, differing only in what it is worth to mine', function () {
    /*
     * **The test the reordering exists for.** Two players, one template, and three claims that have to
     * hold together:
     *
     * - both home systems hold the template's nine planets, with the same types and the same
     *   habitability, so neither player has a better place to live than the other;
     * - both home *worlds* are identical down to their deposits, because that one planet is settled
     *   completely;
     * - their other planets' deposits differ, because those are drawn per player — which is the whole
     *   of what a home is allowed to differ in.
     *
     * The third is what stops this passing for the wrong reason: a stage that copied the template over
     * the entire system would satisfy the first two.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    seatPlayers($game, 2);

    generateTemplate($gamemaster, $game, 4242, templateDocument());
    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia_template'])
    );

    generateStage($gamemaster, $game, GenerationStage::HomeStellia, 3);
    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    );

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213)->assertSessionHasNoErrors();

    $homes = HomeStellium::query()->with('location.stellium.stars.planets')->get();

    expect($homes)->toHaveCount(2);

    $systems = $homes->map(
        fn (HomeStellium $home): Star => $home->location->stellium->stars->sole()
    );

    $shape = fn (Star $star): array => $star->planets
        ->map(fn (Planet $planet): array => [$planet->ordinal, $planet->type->value, $planet->habitability])
        ->all();

    $worth = fn (Star $star): array => $star->planets
        ->map(fn (Planet $planet): array => [$planet->fuel, $planet->metals, $planet->minerals])
        ->all();

    /* Nine planets each, exactly as the document described them. */
    expect($shape($systems[0]))->toHaveCount(9);
    expect($shape($systems[0]))->toBe($shape($systems[1]));

    $homeWorld = fn (Star $star): Planet => $star->planets->firstWhere('ordinal', 3);

    expect($homeWorld($systems[0])->habitability)->toBe(25);
    expect([$homeWorld($systems[0])->fuel, $homeWorld($systems[0])->metals, $homeWorld($systems[0])->minerals])
        ->toBe([5, 5, 5])
        ->toBe([$homeWorld($systems[1])->fuel, $homeWorld($systems[1])->metals, $homeWorld($systems[1])->minerals]);

    /* And everything around it was drawn for each of them separately. */
    expect($worth($systems[0]))->not->toBe($worth($systems[1]));
});

test('a system nobody begins at is drawn exactly as it always was', function () {
    /*
     * The other side of the same rule: the template reaches homes and nothing else. A cluster whose
     * ordinary systems had picked up nine planets apiece would be a very different game.
     */
    $game = Game::factory()->create();
    $gamemaster = withAcceptedStelliums($game);

    seatPlayers($game, 2);

    generateTemplate($gamemaster, $game, 4242, templateDocument());
    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia_template'])
    );

    generateStage($gamemaster, $game, GenerationStage::HomeStellia, 3);
    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    );

    generateStage($gamemaster, $game, GenerationStage::Planets, 88_213);

    $homeLocations = HomeStellium::query()->pluck('location_id')->all();

    $ordinary = Star::query()
        ->withCount('planets')
        ->whereHas('stellium', fn ($query) => $query->whereNotIn('location_id', $homeLocations))
        ->get();

    /* The 3d4−2 range, which a nine-planet template would sit inside but not fill. */
    $counts = $ordinary->pluck('planets_count')->unique()->sort()->values()->all();

    expect($ordinary)->toHaveCount(139);
    expect(min($counts))->toBeGreaterThanOrEqual(1);
    expect(max($counts))->toBeLessThanOrEqual(10);
    expect($counts)->not->toBe([9]);

    $run = $game->generationRuns()->standing()->where('stage', GenerationStage::Planets)->sole();

    expect($run->summary['homes'])->toBe(2);
    expect($run->summary['home_planets'])->toBe(9);
});
