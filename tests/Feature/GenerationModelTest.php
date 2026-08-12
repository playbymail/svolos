<?php

use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStage;
use App\Enums\GenerationStageState;
use App\Models\Game;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Star;
use App\Models\Stellium;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The generation models
|--------------------------------------------------------------------------
|
| The state of a game's generation is **derived** from its runs — there is no stage column — so the
| derivation is what has to be pinned. Everything a screen shows and every 403 the controller raises
| reads these methods, and a bug in them would look like a workflow that skips a step rather than
| like a model with a wrong answer.
|
| The other thing here is the `Stellium` table name, which is a trap rather than a decision: Laravel's
| inflector makes the plural `Stellia`.
|
*/

test('the inflector would put stelliums in the wrong table, which is why the model names it', function () {
    /*
     * The first expectation is the *hazard*, asserted so this test fails loudly if a future Laravel
     * ever fixes the inflection and makes the override unnecessary — at which point somebody can
     * remove it deliberately rather than discover it. The second is the override doing its job.
     */
    expect(Str::plural('Stellium'))->toBe('Stellia');
    expect(Str::snake(Str::pluralStudly('Stellium')))->toBe('stellia');

    expect((new Stellium)->getTable())->toBe('stelliums');
    expect(Stellium::query()->count())->toBe(0);
});

test('a run status is derived from its timestamps', function () {
    $pending = GenerationRun::factory()->create();
    $accepted = GenerationRun::factory()->accepted()->create();
    $superseded = GenerationRun::factory()->superseded()->create();

    expect($pending->status())->toBe(GenerationRunStatus::Pending);
    expect($accepted->status())->toBe(GenerationRunStatus::Accepted);
    expect($superseded->status())->toBe(GenerationRunStatus::Superseded);

    expect($pending->isPending())->toBeTrue();
    expect($accepted->isPending())->toBeFalse();
    expect($superseded->isPending())->toBeFalse();
    expect($accepted->isAccepted())->toBeTrue();
});

test('the standing scope keeps the pending and accepted runs and drops the replaced ones', function () {
    $game = Game::factory()->create();

    $superseded = GenerationRun::factory()->for($game)->superseded()->create(['attempt' => 1]);
    $accepted = GenerationRun::factory()->for($game)->accepted()->create(['attempt' => 2]);

    expect($game->generationRuns()->standing()->pluck('id')->all())->toBe([$accepted->id]);
    expect($game->generationRuns()->count())->toBe(2);
    expect(GenerationRun::query()->whereKey($superseded->getKey())->exists())->toBeTrue();
});

test('a game finds only the standing run for a stage', function () {
    $game = Game::factory()->create();

    GenerationRun::factory()->for($game)->superseded()->create(['attempt' => 1, 'seed' => 111]);
    GenerationRun::factory()->for($game)->create(['attempt' => 2, 'seed' => 222]);

    $game->load('generationRuns');

    expect($game->generationRunFor(GenerationStage::Cluster)?->seed)->toBe(222);
    expect($game->generationRunFor(GenerationStage::Stelliums))->toBeNull();
});

test('a stage is locked until the stage before it has been accepted', function () {
    $game = Game::factory()->create();

    /* Nothing generated: the first stage is ready, everything after it waits. */
    $game->load('generationRuns');

    expect($game->generationStateFor(GenerationStage::Cluster))->toBe(GenerationStageState::Ready);
    expect($game->generationStateFor(GenerationStage::Stelliums))->toBe(GenerationStageState::Locked);

    /* A pending cluster is under review, and still does not unlock what follows it. */
    $cluster = GenerationRun::factory()->for($game)->create();
    $game->load('generationRuns');

    expect($game->generationStateFor(GenerationStage::Cluster))->toBe(GenerationStageState::Review);
    expect($game->generationStateFor(GenerationStage::Stelliums))->toBe(GenerationStageState::Locked);

    $cluster->accepted_at = now();
    $cluster->save();
    $game->load('generationRuns');

    expect($game->generationStateFor(GenerationStage::Cluster))->toBe(GenerationStageState::Accepted);
    expect($game->generationStateFor(GenerationStage::Stelliums))->toBe(GenerationStageState::Ready);
});

test('generation is complete only when every stage has been accepted', function () {
    /*
     * The check sweeps `GenerationStage::cases()` rather than naming the last stage, so adding the
     * planets stage will make every unfinished game incomplete again — which is the intended
     * behaviour, and the reason this asserts against the enum rather than against a number.
     */
    $game = Game::factory()->create();
    $game->load('generationRuns');

    expect($game->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::Cluster);

    GenerationRun::factory()->for($game)->stage(GenerationStage::Cluster)->accepted()->create();
    $game->load('generationRuns');

    expect($game->isGenerationComplete())->toBeFalse();
    expect($game->firstUnfinishedGenerationStage())->toBe(GenerationStage::Stelliums);

    GenerationRun::factory()->for($game)->stage(GenerationStage::Stelliums)->accepted()->create();
    $game->load('generationRuns');

    expect($game->isGenerationComplete())->toBeTrue();
    expect($game->firstUnfinishedGenerationStage())->toBeNull();
});

test('the next attempt number counts the runs that were thrown away', function () {
    /*
     * "Attempt 3" has to keep meaning the third time somebody asked, even though the first two
     * produced nothing that still exists.
     */
    $game = Game::factory()->create();

    GenerationRun::factory()->for($game)->superseded()->create(['attempt' => 1]);
    GenerationRun::factory()->for($game)->superseded()->create(['attempt' => 2]);
    $game->load('generationRuns');

    expect($game->nextGenerationAttemptFor(GenerationStage::Cluster))->toBe(3);
    /* Attempts are counted per stage, so an untouched stage starts at one. */
    expect($game->nextGenerationAttemptFor(GenerationStage::Stelliums))->toBe(1);
});

test('a game with no runs says so, whether or not the relation is loaded', function () {
    /*
     * `hasGenerationRuns()` is what closes the base seed, and it is asked both of a game whose runs
     * are loaded (the game screen) and of one hundred games in a list. Both paths have to agree.
     */
    $game = Game::factory()->create();

    expect($game->hasGenerationRuns())->toBeFalse();

    GenerationRun::factory()->for($game)->create();

    expect(Game::query()->findOrFail($game->id)->hasGenerationRuns())->toBeTrue();
    expect(Game::query()->with('generationRuns')->findOrFail($game->id)->hasGenerationRuns())->toBeTrue();
});

test('discarding a run deletes what it produced, all the way down to the stars', function () {
    /*
     * One cascade chain — run → locations → stelliums → stars — so nothing has to remember to clean up
     * after a regeneration. Asserted at the database rather than through the actions, because the
     * constraint is what makes the actions safe.
     */
    $game = Game::factory()->create();
    $run = GenerationRun::factory()->for($game)->create();

    $location = Location::factory()->for($game)->create(['generation_run_id' => $run->id]);
    Stellium::factory()->for($location)->withStars(3)->create();

    expect(Star::query()->count())->toBe(3);

    $run->delete();

    expect(Location::query()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    expect(Star::query()->count())->toBe(0);
});

test('a location knows how far it is from the centre', function () {
    $location = Location::factory()->at(3, 4, 12)->create();

    /* 3-4-12 is a Pythagorean quadruple, so this is exact rather than nearly right. */
    expect($location->radius())->toBe(13.0);
});
