<?php

use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStage;
use App\Enums\GenerationStageState;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Models\Entity;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\HomeStellium;
use App\Models\Location;
use App\Models\Planet;
use App\Models\Star;
use App\Models\Stellium;
use App\Models\Unit;
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

    /* And the same trap, one word longer, for the table that says where each player begins. */
    expect(Str::snake(Str::pluralStudly('HomeStellium')))->toBe('home_stellia');

    expect((new HomeStellium)->getTable())->toBe('home_stelliums');
    expect(HomeStellium::query()->count())->toBe(0);
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
     * The check sweeps `GenerationStage::cases()` rather than naming the last stage, so adding a stage
     * makes every unfinished game incomplete again — which is the intended behaviour, and the reason
     * this asserts against the enum rather than against a number.
     *
     * **The walk is over `cases()` too, and that is the point of writing it this way.** Spelled out
     * stage by stage, this test had to be rewritten every time one was added and again when they were
     * reordered — and each rewrite was a chance to encode the new order in two places instead of one.
     * Driven by the enum it asserts nothing about which stages exist, only that they open one at a
     * time and in the declared order, which is the actual rule.
     */
    $game = Game::factory()->create();
    $game->load('generationRuns');

    foreach (GenerationStage::cases() as $stage) {
        expect($game->isGenerationComplete())->toBeFalse();
        expect($game->firstUnfinishedGenerationStage())->toBe($stage);

        GenerationRun::factory()->for($game)->stage($stage)->accepted()->create();
        $game->load('generationRuns');
    }

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

test('discarding a run deletes what it produced, all the way down to the planets', function () {
    /*
     * One cascade chain — run → locations → stelliums → stars → planets — so nothing has to remember to
     * clean up after a regeneration. Asserted at the database rather than through the actions, because
     * the constraint is what makes the actions safe. Four levels now, and the last one is the one a
     * hand-written cleanup would forget.
     */
    $game = Game::factory()->create();
    $run = GenerationRun::factory()->for($game)->create();

    $location = Location::factory()->for($game)->create(['generation_run_id' => $run->id]);
    $stellium = Stellium::factory()->for($location)->withStars(3)->create();

    Planet::factory()
        ->for($stellium->stars->first())
        ->for($run)
        ->count(2)
        ->sequence(fn ($sequence) => ['ordinal' => $sequence->index + 1])
        ->create();

    expect(Star::query()->count())->toBe(3);
    expect(Planet::query()->count())->toBe(2);

    $run->delete();

    expect(Location::query()->count())->toBe(0);
    expect(Stellium::query()->count())->toBe(0);
    expect(Star::query()->count())->toBe(0);
    expect(Planet::query()->count())->toBe(0);
});

test('names each of the four stars a stellium can hold, and refuses a fifth', function () {
    /*
     * The letters are the one thing on screen that comes from a star rather than from a planet, and the
     * four are a real ceiling — `StelliumGenerator::STAR_DISTRIBUTION` never draws more. The fifth case
     * is the point of the test: naming it `E` is what the arithmetic used to do, and a star nobody can
     * account for should stop rather than quietly appear in the system panel.
     */
    $stellium = Stellium::factory()->withStars(4)->create();

    expect($stellium->stars->map(fn (Star $star): string => $star->label())->all())
        ->toBe(['A', 'B', 'C', 'D']);

    $fifth = Star::factory()->for($stellium)->make(['ordinal' => 5]);

    expect(fn () => $fifth->label())->toThrow(LogicException::class);
});

test('deleting a run frees every home standing on it, and keeps the seat', function () {
    /*
     * The home stellia are a **branch** off the same chain rather than a fifth level of it: they hang
     * straight off the run, and off a seat that must survive them. Both halves are asserted together,
     * because a mistaken `cascadeOnDelete()` on `game_seat_id` would satisfy the first on its own — by
     * deleting the player's place at the game along with their starting system.
     */
    $game = Game::factory()->create();
    $run = GenerationRun::factory()->for($game)->stage(GenerationStage::HomeStellia)->create();

    $seat = GameSeat::factory()->for($game)->create();
    $location = Location::factory()->for($game)->create(['generation_run_id' => $run->id]);

    HomeStellium::factory()->create([
        'generation_run_id' => $run->id,
        'game_seat_id' => $seat->id,
        'location_id' => $location->id,
    ]);

    expect(HomeStellium::query()->count())->toBe(1);

    $run->delete();

    expect(HomeStellium::query()->count())->toBe(0);
    expect(GameSeat::query()->whereKey($seat->id)->exists())->toBeTrue();
});

test('deleting a run takes the entities it placed, their units, and neither the seat nor the world', function () {
    /*
     * The units stage's branch, and it has one more level than the homes do. Three things are
     * asserted together because each would satisfy the others' absence: the entities go with the run,
     * the units go with the entities — through the **database's** cascade, since a mass delete fires
     * no model events — and neither the seat nor the planet is dragged down with them. A stray
     * `cascadeOnDelete()` read the wrong way round would delete a world because somebody regenerated
     * who was standing on it.
     */
    $game = Game::factory()->create();
    $run = GenerationRun::factory()->for($game)->stage(GenerationStage::Assets)->create();

    $seat = GameSeat::factory()->for($game)->create();
    $planet = Planet::factory()->create();

    $entity = Entity::factory()->for($run)->create([
        'game_seat_id' => $seat->id,
        'planet_id' => $planet->id,
    ]);

    Unit::factory()->for($entity)->create();

    expect(Unit::query()->count())->toBe(1);

    $run->delete();

    expect(Entity::query()->count())->toBe(0);
    expect(Unit::query()->count())->toBe(0);
    expect(GameSeat::query()->whereKey($seat->id)->exists())->toBeTrue();
    expect(Planet::query()->whereKey($planet->id)->exists())->toBeTrue();
});

test('an entity built in play belongs to no run', function () {
    /*
     * The one nullable run key in the schema, and the reason it is nullable: these first entities were
     * placed by the units stage, but a ship built during play was placed by nobody. The factory
     * leaves it null by default so that a test asking for the other one has to say so.
     */
    $entity = Entity::factory()->create();

    expect($entity->generation_run_id)->toBeNull();
    expect($entity->generationRun)->toBeNull();
});

test('deleting an entity takes what it was holding', function () {
    $entity = Entity::factory()->create();

    Unit::factory()->for($entity)->create();

    $entity->delete();

    expect(Unit::query()->count())->toBe(0);
});

test('a unit weighs and takes up its kind times its quantity', function () {
    /*
     * The same arithmetic `UnitHolding` does, asserted again on the row because the two are separate
     * classes: a holding describes what is about to be written and a unit is what was written, and
     * a screen reads the second.
     */
    $unit = Unit::factory()->create([
        'type' => UnitType::LightStructural,
        'inventory' => Inventory::Components,
        'quantity' => 20,
    ]);

    expect($unit->mass())->toBe(UnitType::LightStructural->mass() * 20);
    expect($unit->volume())->toBe(UnitType::LightStructural->assembledVolume() * 20);
});

test('a location knows how many hexes away another one is', function () {
    /*
     * The metric the home stellia are placed against, reachable from a row. Not `radius()`: these two
     * share a column and are twenty apart in height, so they are the same hex and zero apart here.
     */
    $stacked = Location::factory()->at(3, -4, -10)->create();
    $above = Location::factory()->at(3, -4, 10)->create();
    $away = Location::factory()->at(-4, 2, 0)->create();

    expect($stacked->hexDistanceTo($above))->toBe(0);
    expect($stacked->hexDistanceTo($away))->toBe(9);
    expect($away->hexDistanceTo($stacked))->toBe(9);
});

test('a location knows how far it is from the centre', function () {
    $location = Location::factory()->at(3, 4, 12)->create();

    /* 3-4-12 is a Pythagorean quadruple, so this is exact rather than nearly right. */
    expect($location->radius())->toBe(13.0);
});
