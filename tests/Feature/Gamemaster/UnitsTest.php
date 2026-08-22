<?php

use App\Enums\EntityType;
use App\Enums\GameRole;
use App\Enums\GenerationStage;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Generation\StartingUnits;
use App\Models\Entity;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Putting everybody on the board
|--------------------------------------------------------------------------
|
| The sixth and last generation stage, and the only one that puts something *on* the map rather than
| drawing more of it: a colony on every player's home world, the ship that carried them in orbit above
| it, and the units each begins holding.
|
| It is a **stage** for the reason the home stellia is one — it adds no routes, and everything about
| when it may run is already covered by `GenerationTest`. Three things are different from every stage
| before it, and each has tests here:
|
| - **one kit settles the game, and every player in it gets that kit.** That is the fairness rule and
|   it is asserted across players rather than against a hard-coded list. What the kit *is* now varies
|   between games: it is drawn from the run's seed, chosen from the gamemaster's library, or uploaded
|   as a document, and all three arrive as `generation_runs.kit`. See `App\Generation\Kit` for why
|   varying it between games leaves the per-player rule untouched;
| - it reads **three** earlier stages at once: the template for which orbit is home, the home stellia
|   for which system, the planets for the world itself. That is why it is last;
| - a player seated after the homes were arranged has nowhere to stand, and is **skipped and counted**
|   rather than failing the run — the remedy is regenerating the homes, and no field on this form
|   would fix it. That is also why the "choose a different seed" rule stays switched off here: the
|   seats are part of this stage's input, so the same seed against a changed roster really does place
|   something new. `GenerationRunRequest::redrawsFromTheRoster()` carries the whole argument.
|
*/

/**
 * Generate the opening position.
 */
function generateUnits(User $gamemaster, Game $game, int $seed, array $kit = []): TestResponse
{
    return test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'assets']),
        ['seed' => $seed, ...$kit],
    );
}

test('the stage is locked until the planets have been drawn', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedHomeStellia($game);

    seatPlayers($game, 2);

    expect($game->load('generationRuns')->generationStateFor(GenerationStage::Assets)->value)
        ->toBe('locked');

    generateUnits($gamemaster, $game, 4242)->assertForbidden();

    expect(Entity::query()->count())->toBe(0);
    expect(Unit::query()->count())->toBe(0);
});

test('every player is given a colony on their home world and a ship above it', function () {
    $game = Game::factory()->create();

    $seats = seatPlayers($game, 3);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242)->assertRedirect();

    $template = $game->load('generationRuns')
        ->generationRunFor(GenerationStage::HomeStelliaTemplate)?->template;

    foreach ($seats as $seat) {
        $entities = Entity::query()->where('game_seat_id', $seat->id)->with('planet.star')->get();

        expect($entities)->toHaveCount(2);
        expect($entities->pluck('type')->all())->toBe([EntityType::OpenAirColony, EntityType::Ship]);

        /*
         * Both stand at the *same* planet: the colony is on it and the ship is in orbit above it, and
         * `type` is the only thing that says which. A ship placed somewhere else would be a second,
         * unstated rule about where a voyage ends.
         */
        expect($entities->pluck('planet_id')->unique())->toHaveCount(1);

        /* And that planet is the home world — the orbit the template settled, in the seat's system. */
        $home = $entities->first()?->planet;

        expect($home?->ordinal)->toBe($template['home_ordinal']);
        expect($home?->star->stellium->location_id)->toBe($seat->homeStellium?->location_id);
    }
});

test('every player is handed exactly the same kit', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 3);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    /*
     * The fairness rule, asserted across players rather than against a hard-coded list: what matters
     * is that nobody begins ahead, and pinning the numbers here would only restate
     * `StartingUnitsTest` while making every tuning change fail in two places.
     */
    $kits = Entity::query()
        ->with('units')
        ->get()
        ->groupBy(fn (Entity $entity): string => $entity->type->value)
        ->map(fn ($entities) => $entities
            ->map(fn (Entity $entity): array => $entity->units
                ->map(fn (Unit $unit): string => "{$unit->type->value}:{$unit->inventory->value}:{$unit->quantity}")
                ->sort()
                ->values()
                ->all())
            ->unique());

    foreach ($kits as $perType) {
        expect($perType)->toHaveCount(1);
    }

    /* And it is the kit the manifest describes, so the two cannot drift apart. */
    $colony = Entity::query()->where('type', EntityType::OpenAirColony)->with('units')->first();

    expect($colony?->units)->toHaveCount(count((new StartingUnits)->openAirColony()));
});

test('the ship is carrying its engines rather than running on them', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    $ship = Entity::query()->where('type', EntityType::Ship)->with('units')->sole();

    $engines = $ship->units->firstWhere('type', UnitType::Engine);

    /* "The main engines are gone. Burned out sometime during the voyage." */
    expect($engines?->inventory)->toBe(Inventory::Cargo);
    expect($ship->units->where('inventory', Inventory::Components)->pluck('type')->all())
        ->toBe([UnitType::LightStructure]);
});

test('the summary counts what was placed, and who was left out', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 2);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    $summary = $game->load('generationRuns')->generationRunFor(GenerationStage::Assets)?->summary;

    expect($summary['players'])->toBe(2);
    expect($summary['colonies'])->toBe(2);
    expect($summary['ships'])->toBe(2);
    expect($summary['units'])->toBe(2 * (count((new StartingUnits)->openAirColony()) + count((new StartingUnits)->ship())));
    expect($summary['players_without_a_home'])->toBe(0);
});

test('generating again with the same seed is allowed, and replaces rather than duplicates', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 2);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    $first = Entity::query()->pluck('id')->all();

    /*
     * **The exemption from the "choose a different seed" rule**, and it is exempt for the opposite
     * reason the home stellia is. There the same seed gives a genuinely new arrangement; here no seed
     * gives anything different at all, so demanding a new number would be asking about the wrong
     * thing. What regenerating is actually for is the test below this one.
     */
    generateUnits($gamemaster, $game, 4242)->assertValid()->assertRedirect();

    $second = Entity::query()->pluck('id')->all();

    expect($second)->toHaveCount(4);
    expect(array_intersect($first, $second))->toBeEmpty();

    /* The superseded run's units went with its entities, through the database's cascade. */
    expect(Unit::query()->count())
        ->toBe(2 * (count((new StartingUnits)->openAirColony()) + count((new StartingUnits)->ship())));
});

test('a player seated after the homes were arranged is skipped and counted', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    /*
     * The hole `Game::playersWithoutHomeStellium()` already reports and `gameStatusRules()` already
     * refuses to let a game start with. This stage neither fails nor quietly places fewer colonies
     * than there are players: it says so on the review card, and the remedy is regenerating the homes.
     */
    $latecomer = GameSeat::factory()->for($game)->for(User::factory())->create();

    generateUnits($gamemaster, $game, 4242)->assertRedirect();

    expect(Entity::query()->where('game_seat_id', $latecomer->id)->count())->toBe(0);

    $summary = $game->load('generationRuns')->generationRunFor(GenerationStage::Assets)?->summary;

    expect($summary['players'])->toBe(2);
    expect($summary['colonies'])->toBe(1);
    expect($summary['players_without_a_home'])->toBe(1);
});

test('gamemasters and retired seats are given nothing', function () {
    $game = Game::factory()->create();

    $seats = seatPlayers($game, 2);
    $gamemaster = withAcceptedPlanets($game);

    /*
     * Retired *after* the homes were arranged, which is the case worth covering: the seat still has a
     * home stellium, and it is the roster read that has to leave it out rather than the absence of a
     * home. A gamemaster runs the game rather than playing it, so it never had one.
     */
    $seats[1]->forceFill(['is_active' => false])->save();

    generateUnits($gamemaster, $game, 4242);

    expect(Entity::query()->where('game_seat_id', $seats[1]->id)->count())->toBe(0);
    expect(Entity::query()->count())->toBe(2);

    $seat = $game->seats()->where('role', GameRole::Gamemaster)->sole();

    expect(Entity::query()->where('game_seat_id', $seat->id)->count())->toBe(0);
});

test('a game with nobody seated places nothing and is still acceptable', function () {
    $game = Game::factory()->create();
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242)->assertRedirect();

    expect(Entity::query()->count())->toBe(0);

    /* An ordinary outcome rather than a failure, so the stage accepts and the game completes. */
    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'assets']))
        ->assertRedirect();

    expect($game->load('generationRuns')->isGenerationComplete())->toBeTrue();
});

test('a game is not complete until everybody has been put on the board', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    /*
     * `isGenerationComplete()` sweeps `GenerationStage::cases()`, so adding this stage made every
     * unfinished game incomplete again. That is the intended behaviour: a game missing a generation
     * step is not ready to be played.
     */
    expect($game->load('generationRuns')->isGenerationComplete())->toBeFalse();

    generateUnits($gamemaster, $game, 4242);

    /* Generated is not accepted: the gamemaster still has to look at it. */
    expect($game->load('generationRuns')->isGenerationComplete())->toBeFalse();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'assets']));

    expect($game->load('generationRuns')->isGenerationComplete())->toBeTrue();
});

test('starting the generation over takes every colony and ship with it, and leaves the roster alone', function () {
    $game = Game::factory()->create();

    $seats = seatPlayers($game, 2);
    $gamemaster = withAcceptedUnits($game);

    expect(Entity::query()->count())->toBe(4);
    expect(Unit::query()->count())->toBeGreaterThan(0);

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.games.generation.restart', ['game' => $game]))
        ->assertRedirect();

    expect(Entity::query()->count())->toBe(0);
    /* The units went through the cascade rather than through a model event: it is a mass delete. */
    expect(Unit::query()->count())->toBe(0);

    /* The roster is untouched, which is what the confirmation dialog promises. */
    expect($game->seats()->count())->toBe(count($seats) + 1);
});

test('an entity survives its player leaving the game', function () {
    $game = Game::factory()->create();

    $seats = seatPlayers($game, 1);
    withAcceptedUnits($game);

    /*
     * Seats are retired rather than deleted precisely so that engine history keeps naming them, and an
     * entity is the first thing that depends on it: retiring the seat that controls a colony must not
     * delete the colony.
     */
    $seats[0]->forceFill(['is_active' => false])->save();

    expect(Entity::query()->where('game_seat_id', $seats[0]->id)->count())->toBe(2);
});

test('the screen carries the opening position, under the world it stands on', function () {
    $game = Game::factory()->create();

    $seats = seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/games/Show')
            ->where('generation.stages.5.stage', 'assets')
            ->where('generation.stages.5.state', 'review')
            ->where('generation.stages.5.summary.colonies', 1)
        );

    /*
     * The opening position rides on `locationDetail` — the optional prop the planets are reviewed
     * through — rather than on a payload of its own. It is the same question asked once: what is at
     * this system, and what is standing in it.
     */
    $home = $seats[0]->homeStellium;

    $response = reloadLocationDetail($gamemaster, $game, (int) $home?->location_id)->assertOk();

    $homeOrdinal = $game->generationRunFor(GenerationStage::HomeStelliaTemplate)?->template['home_ordinal'];

    $planets = collect($response->json('props.locationDetail.stars.0.planets'));

    $settled = $planets->firstWhere('ordinal', $homeOrdinal);

    expect(collect($settled['entities'])->pluck('type')->all())->toBe(['open_air_colony', 'ship']);
    /*
     * The **empire's** name, not the account's. An unnamed empire falls back to "Game ACME Seat 3",
     * which is what these seats have — inside a game an empire is named by its empire name on every
     * screen, and the roster below this panel is what says which account holds it.
     */
    expect($settled['entities'][0]['player_name'])->toBe($seats[0]->empireName());
    expect($settled['entities'][0]['player_name'])->not->toBe($seats[0]->user->name);
    expect($settled['entities'][0]['seat_id'])->toBe($seats[0]->id);

    expect(array_keys($settled['entities'][0]))
        ->toBe(['id', 'type', 'type_label', 'seat_id', 'player_name', 'units']);

    expect(array_keys($settled['entities'][0]['units'][0]))
        ->toBe(['id', 'type', 'type_label', 'inventory', 'assignment_label', 'technology_level', 'quantity']);

    /*
     * **The inventories arrive in contiguous runs, in the enum's declaration order.** Asserted as the
     * whole sequence rather than as "components is first", because first-is-right passes on a
     * list that is otherwise interleaved — which is what a `sortBy()` given an array of closures
     * silently produces, and what `LocationSystemPanel` turns into a duplicate `{#each}` key and a
     * panel that never stops loading. The property the screen depends on is that an inventory
     * appears in one run, so that is the property pinned here.
     */
    foreach ($settled['entities'] as $entity) {
        $inventories = array_column($entity['units'], 'inventory');
        $runs = array_values(array_unique($inventories));

        expect($runs)->toBe(array_values(array_intersect(
            array_map(fn (Inventory $case): string => $case->value, Inventory::cases()),
            $inventories,
        )));

        /*
         * Contiguous: collapsing neighbours has to leave the same list as taking each inventory
         * once. They differ the moment one appears in two places.
         */
        $collapsed = [];

        foreach ($inventories as $inventory) {
            if (end($collapsed) !== $inventory) {
                $collapsed[] = $inventory;
            }
        }

        expect($collapsed)->toBe($runs);
    }

    /* Components first, because it is the part that says what the thing is. */
    expect($settled['entities'][0]['units'][0]['inventory'])->toBe('components');

    /*
     * And every other world in the system carries an empty list rather than no key at all: a screen
     * that had to tell "nobody is here" from "this payload predates the stage" would be reading a
     * distinction the server never meant to draw.
     */
    $empty = $planets->firstWhere('ordinal', $homeOrdinal === 1 ? 2 : 1);

    expect($empty['entities'])->toBe([]);
});

test('the administrator sees the opening position too, read only', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    withAcceptedUnits($game);

    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator)
        ->get(route('admin.games.show', ['game' => $game]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/games/Show')
            ->where('generation.stages.5.stage', 'assets')
            ->where('generation.stages.5.state', 'accepted')
            /* And no controls: building the world is the gamemaster's. */
            ->missing('generation.stages.5.suggested_seed')
        );
});

test('an administrator without a seat cannot generate the stage', function () {
    /*
     * `/gamemaster` reads an active gamemaster seat and never `users.role`, so an administrator is
     * refused here on purpose. Asserted per stage because the sweep is per route, and this stage
     * shares its route with every other one.
     */
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    withAcceptedPlanets($game);

    $administrator = User::factory()->admin()->create();

    generateUnits($administrator, $game, 4242)->assertForbidden();

    expect(Entity::query()->count())->toBe(0);
});

test('the kit is drawn from the seed, so two games open differently', function () {
    /*
     * The feature, and the half of it a per-player assertion cannot see: every player in one game
     * gets the same kit *and* two games with different seeds get different kits. Asserting only the
     * first would pass just as well on the old behaviour, where every game in the world opened
     * identically.
     */
    $kits = collect([4242, 99])->map(function (int $seed): array {
        $game = Game::factory()->create();

        seatPlayers($game, 1);
        $gamemaster = withAcceptedPlanets($game);

        generateUnits($gamemaster, $game, $seed);

        return Unit::query()
            ->whereHas('entity', fn ($query) => $query->whereHas('gameSeat', fn ($seats) => $seats->where('game_id', $game->id)))
            ->orderBy('id')
            ->pluck('quantity')
            ->all();
    });

    expect($kits[0])->not->toBe($kits[1]);
});

test('a saved kit can be chosen, and is copied onto the run rather than referenced', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    $kitTemplate = kitTemplateFor($gamemaster, seed: 77);

    generateUnits($gamemaster, $game, 5, [
        'kit_source' => 'saved',
        'kit_template_id' => $kitTemplate->id,
    ])->assertSessionHasNoErrors();

    $run = $game->fresh()?->generationRuns()->where('stage', 'assets')->sole();

    expect($run?->kit['entities'])->toBe($kitTemplate->document['entities']);

    /* And what was actually placed is that kit, not one drawn from the run's own seed of 5. */
    $colony = Entity::query()->where('type', EntityType::OpenAirColony)->with('units')->sole();

    expect($colony->units->sortBy('id')->pluck('quantity')->values()->all())
        ->toBe(array_column($kitTemplate->document['entities'][0]['holdings'], 'quantity'));
});

test('somebody else kit is refused with a message rather than a 403', function () {
    /*
     * A kit template is a **posted value**, not a route-bound model, so it falls on the message side
     * of the line this area draws — see `Gamemaster\GenerationController`. The `exists` rule is
     * scoped to the signed-in account, which gets the refusal and its sentence in one rule and cannot
     * leak whether somebody else's kit with that id exists.
     */
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    $theirs = kitTemplateFor(gamemasterOf(Game::factory()->create()), name: 'Theirs');

    generateUnits($gamemaster, $game, 5, [
        'kit_source' => 'saved',
        'kit_template_id' => $theirs->id,
    ])->assertSessionHasErrors('kit_template_id');

    expect(Entity::query()->count())->toBe(0);
});

test('a kit can be uploaded, and the document is what gets placed', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 5, [
        'kit_source' => 'upload',
        'kit' => kitDocumentFile(function (array $document): array {
            $document['entities'][0]['holdings'][0]['quantity'] = 3;

            return $document;
        }, name: 'opening.json'),
    ])->assertSessionHasNoErrors();

    $run = $game->fresh()?->generationRuns()->where('stage', 'assets')->sole();

    expect($run?->kit['file'])->toBe('opening.json');

    $colony = Entity::query()->where('type', EntityType::OpenAirColony)->with('units')->sole();

    expect($colony->units->sortBy('id')->first()?->quantity)->toBe(3);

    /* The card says which of the three it was, and drops the seed it did not use. */
    expect($run?->summary['kit_file'])->toBe('opening.json');
});

test('an uploaded kit that is not a kit is refused on the kit field, and writes no run', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 5, [
        'kit_source' => 'upload',
        'kit' => kitDocumentFile(fn (array $document): array => [
            ...$document,
            'entities' => [$document['entities'][0]],
        ]),
    ])->assertSessionHasErrors('kit');

    /*
     * Parsed at the edge, inside the controller's `try`, so a document nobody could read leaves no
     * run row at all — the same reasoning the home template stage gives.
     */
    expect($game->fresh()?->generationRuns()->where('stage', 'assets')->count())->toBe(0);
    expect(Entity::query()->count())->toBe(0);
});

test('the summary names the seed a drawn kit came from', function () {
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    generateUnits($gamemaster, $game, 4242);

    $run = $game->fresh()?->generationRuns()->where('stage', 'assets')->sole();

    expect($run?->summary['kit_seed'])->toBe(4242);
    /* Null, and `GenerationStageCard` drops nulls, so nothing renders the word "null" beside a label. */
    expect($run?->summary['kit_file'])->toBeNull();
});
