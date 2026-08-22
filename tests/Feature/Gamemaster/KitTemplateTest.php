<?php

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\KitTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| A gamemaster's private library of opening kits
|--------------------------------------------------------------------------
|
| `/gamemaster/kit-templates` is where somebody who runs a game keeps the kits their players might
| open with: draw one from a seed, edit its holdings, download it, upload one back.
|
| It is the **second** group in the gamemaster area and the only one with no `{game}` in its URLs,
| which is what this file exists to pin from three sides:
|
| - **the gate is `runs-a-game`, not `gamemaster`.** The game gate reads `{game}` and aborts without
|   one, so it would refuse everybody here. The route-level half of that is swept in
|   `GameManagementTest`; what is asserted here is who actually gets in.
| - **the library is private.** Every action naming a kit is a 403 for anybody but its owner, and the
|   index never leaves the signed-in account's shelf. Ownership is a route-bound model's state, so it
|   is a 403 rather than a message — the line the whole area draws.
| - **the round trip is lossless.** Download, edit, upload, and the seed survives. That is the reason
|   the seed lives inside the document at all, so it is asserted end to end rather than in pieces.
|
*/

/**
 * Somebody who runs a game somewhere, and so may reach the library.
 */
function libraryUser(): User
{
    return gamemasterOf(Game::factory()->create());
}

test('somebody who runs a game can see their own kits and nobody else can see them', function () {
    $mine = libraryUser();
    $theirs = libraryUser();

    kitTemplateFor($mine, name: 'Mine');
    kitTemplateFor($theirs, name: 'Theirs');

    $this->actingAs($mine)
        ->get(route('gamemaster.kit-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/kit-templates/Index')
            ->has('kits', 1)
            ->where('kits.0.name', 'Mine'));
});

test('the library refuses a guest, a member with no seat, and a player', function () {
    $game = Game::factory()->create();

    $player = User::factory()->create();
    GameSeat::factory()->for($game)->for($player)->create();

    $this->get(route('gamemaster.kit-templates.index'))->assertRedirect(route('login'));

    /*
     * An administrator who runs no game is refused too, exactly as the game gate refuses one without
     * a seat. Being an administrator says nothing about game membership — see `.ai/rules/roles.md`.
     */
    foreach ([User::factory()->create(), User::factory()->admin()->create(), $player] as $refused) {
        $this->actingAs($refused)
            ->get(route('gamemaster.kit-templates.index'))
            ->assertForbidden();
    }
});

test('a retired gamemaster seat opens nothing', function () {
    $game = Game::factory()->create();
    $gamemaster = User::factory()->create();

    GameSeat::factory()->for($game)->for($gamemaster)->gamemaster()->retired()->create();

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.kit-templates.index'))
        ->assertForbidden();
});

test('a kit can be drawn from a seed', function () {
    $gamemaster = libraryUser();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'Lean start',
            'source' => 'generate',
            'seed' => 4242,
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Lean start was drawn from seed 4242.',
        ]);

    $kitTemplate = KitTemplate::query()->sole();

    expect($kitTemplate->name)->toBe('Lean start');
    expect($kitTemplate->user_id)->toBe($gamemaster->id);
    expect($kitTemplate->seed)->toBe(4242);
    /* Drawn rather than read, and that null is the only thing that says so afterwards. */
    expect($kitTemplate->file)->toBeNull();
    expect($kitTemplate->kit()->for(EntityType::Ship))->not->toBeEmpty();
});

test('a kit can be uploaded, and the document it came from is remembered', function () {
    $gamemaster = libraryUser();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'From a file',
            'source' => 'upload',
            'kit' => kitDocumentFile(name: 'opening.json'),
        ])
        ->assertRedirect();

    $kitTemplate = KitTemplate::query()->sole();

    expect($kitTemplate->file)->toBe('opening.json');
    /* The seed rode in with the document, which is what makes the round trip worth anything. */
    expect($kitTemplate->seed)->toBe(4242);
});

test('a document that is not a kit is refused on the kit field, and saves nothing', function () {
    $gamemaster = libraryUser();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'Broken',
            'source' => 'upload',
            'kit' => kitDocumentFile(fn (array $document): array => [
                ...$document,
                'entities' => [$document['entities'][0]],
            ]),
        ])
        ->assertSessionHasErrors('kit');

    /*
     * Parsing happens before the row is written, so a document nobody could read leaves nothing
     * behind — an unreadable document is not an attempt at anything.
     */
    expect(KitTemplate::query()->count())->toBe(0);
});

test('two kits of one gamemaster cannot share a name, but two gamemasters can', function () {
    $mine = libraryUser();
    $theirs = libraryUser();

    kitTemplateFor($mine, name: 'Lean start');

    $this->actingAs($mine)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'Lean start',
            'source' => 'generate',
            'seed' => 7,
        ])
        ->assertSessionHasErrors('name');

    /* Private shelves, so the same name on somebody else's is not a collision at all. */
    $this->actingAs($theirs)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'Lean start',
            'source' => 'generate',
            'seed' => 7,
        ])
        ->assertSessionHasNoErrors();
});

test('a kit can be edited, and the seed it was drawn from survives the edit', function () {
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $document = $kitTemplate->document;
    $document['entities'][0]['holdings'][0]['quantity'] = 42;

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.kit-templates.update', $kitTemplate), [
            'name' => 'Edited',
            'entities' => $document['entities'],
        ])
        ->assertRedirect(route('gamemaster.kit-templates.show', $kitTemplate));

    $fresh = $kitTemplate->fresh();

    expect($fresh?->name)->toBe('Edited');
    expect($fresh?->document['entities'][0]['holdings'][0]['quantity'])->toBe(42);
    /*
     * Editing does not make a kit stop being the one drawn from 4242; it makes it an edited version
     * of that one. Throwing the provenance away on the first save would defeat carrying the seed in
     * the document at all.
     */
    expect($fresh?->seed)->toBe(4242);
    expect($fresh?->document['seed'])->toBe(4242);
});

test('an edit is held to every rule an upload is', function () {
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $document = $kitTemplate->document;

    /* A mine is a thing a colony operates, never a thing it is built from. */
    $document['entities'][0]['holdings'][0] = [
        'type' => UnitType::Mine->value,
        'inventory' => Inventory::Components->value,
        'technology_level' => 10,
        'quantity' => 4,
    ];

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.kit-templates.update', $kitTemplate), [
            'name' => $kitTemplate->name,
            'entities' => $document['entities'],
        ])
        ->assertSessionHasErrors('kit');

    expect($kitTemplate->fresh()?->document)->toBe($kitTemplate->document);
});

test('an edit posted the way a browser posts one is saved', function () {
    /*
     * **The regression this exists for.** An HTML form posts strings, and `Kit` requires real
     * integers — correctly, because an uploaded document is JSON where `"quantity": "31"` really is
     * malformed. The `integer` rule accepts a numeric string and `validated()` hands the string
     * straight back, so without `prepareForValidation()` every edit made in a browser was refused
     * with "needs a whole quantity" while every test passed: a test that feeds the stored document
     * back is feeding real integers, which is the one shape a form can never send.
     *
     * So this posts what the browser actually posts. It is the difference between the two payloads
     * that is being asserted, not the saving.
     */
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $entities = array_map(
        fn (array $entity): array => [
            ...$entity,
            'holdings' => array_map(
                fn (array $holding): array => [
                    ...$holding,
                    'quantity' => (string) $holding['quantity'],
                    'technology_level' => (string) $holding['technology_level'],
                ],
                $entity['holdings'],
            ),
        ],
        $kitTemplate->document['entities'],
    );

    $entities[0]['holdings'][0]['quantity'] = '31';

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.kit-templates.update', $kitTemplate), [
            'name' => $kitTemplate->name,
            'entities' => $entities,
        ])
        ->assertSessionHasNoErrors();

    $fresh = $kitTemplate->fresh();

    /* Stored as a number, not as the string it arrived as. */
    expect($fresh?->document['entities'][0]['holdings'][0]['quantity'])->toBe(31);
    expect($fresh?->document['entities'][0]['holdings'][0]['technology_level'])->toBeInt();
});

test('a removed holding leaves the rest saved in order', function () {
    /*
     * The editor names its fields by loop index, so removing a row reindexes everything after it.
     * What this pins is the server half: a contiguous list arrives and is stored in the order it was
     * posted, one holding shorter.
     */
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $entities = $kitTemplate->document['entities'];

    $removed = $entities[0]['holdings'][2];
    unset($entities[0]['holdings'][2]);
    $entities[0]['holdings'] = array_values($entities[0]['holdings']);

    $this->actingAs($gamemaster)
        ->put(route('gamemaster.kit-templates.update', $kitTemplate), [
            'name' => $kitTemplate->name,
            'entities' => $entities,
        ])
        ->assertSessionHasNoErrors();

    $saved = $kitTemplate->fresh()?->document['entities'][0]['holdings'] ?? [];

    expect($saved)->toHaveCount(count($kitTemplate->document['entities'][0]['holdings']) - 1);
    expect(array_column($saved, 'type'))->not->toContain($removed['type']);
    expect($saved)->toBe($entities[0]['holdings']);
});

test('a kit downloads as the document it is, seed included', function () {
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster, name: 'Lean start');

    $response = $this->actingAs($gamemaster)
        ->get(route('gamemaster.kit-templates.download', $kitTemplate))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="lean-start.json"');

    $document = $response->json();

    /* What comes out is exactly what `store()` accepts, which is the whole point of the seed key. */
    expect($document['seed'])->toBe(4242);
    expect($document)->toHaveKeys(['seed', 'file', 'entities']);
    expect(array_column($document['entities'], 'type'))
        ->toBe(array_map(fn (EntityType $type): string => $type->value, EntityType::startingKinds()));
});

test('a downloaded kit uploads back unchanged', function () {
    $gamemaster = libraryUser();
    $original = kitTemplateFor($gamemaster, name: 'Original');

    $document = $this->actingAs($gamemaster)
        ->get(route('gamemaster.kit-templates.download', $original))
        ->json();

    $this->actingAs($gamemaster)
        ->post(route('gamemaster.kit-templates.store'), [
            'name' => 'Round tripped',
            'source' => 'upload',
            'kit' => UploadedFile::fake()
                ->createWithContent('original.json', (string) json_encode($document)),
        ])
        ->assertSessionHasNoErrors();

    $copy = KitTemplate::query()->where('name', 'Round tripped')->sole();

    expect($copy->seed)->toBe($original->seed);
    expect($copy->document['entities'])->toBe($original->document['entities']);
});

test('a kit can be deleted', function () {
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $this->actingAs($gamemaster)
        ->delete(route('gamemaster.kit-templates.destroy', $kitTemplate))
        ->assertRedirect(route('gamemaster.kit-templates.index'));

    expect(KitTemplate::query()->count())->toBe(0);
});

test('every action naming somebody else kit is refused, and changes nothing', function () {
    $mine = libraryUser();
    $theirs = libraryUser();

    $kitTemplate = kitTemplateFor($theirs, name: 'Theirs');

    $this->actingAs($mine);

    $this->get(route('gamemaster.kit-templates.show', $kitTemplate))->assertForbidden();
    $this->get(route('gamemaster.kit-templates.download', $kitTemplate))->assertForbidden();
    $this->put(route('gamemaster.kit-templates.update', $kitTemplate), [
        'name' => 'Taken',
        'entities' => $kitTemplate->document['entities'],
    ])->assertForbidden();
    $this->delete(route('gamemaster.kit-templates.destroy', $kitTemplate))->assertForbidden();

    $fresh = $kitTemplate->fresh();

    expect($fresh?->name)->toBe('Theirs');
    expect($fresh)->not->toBeNull();
});

test('the editor is given the catalogue rather than restating it on the client', function () {
    $gamemaster = libraryUser();
    $kitTemplate = kitTemplateFor($gamemaster);

    $this->actingAs($gamemaster)
        ->get(route('gamemaster.kit-templates.show', $kitTemplate))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gamemaster/kit-templates/Show')
            ->has('kit.entities', 2)
            /*
             * Every kind, with the inventories it may sit in and whether it carries a technology
             * level. Shipped from the enum so the client holds no second copy of a rule that would
             * eventually disagree — see `.ai/rules/units.md`.
             */
            ->has('catalogue.unit_types', count(UnitType::cases()))
            ->has('catalogue.inventories', count(Inventory::cases()))
            ->where('catalogue.maximum_technology_level', UnitType::MAXIMUM_TECHNOLOGY_LEVEL));
});

test('deleting a kit leaves a game that used it alone', function () {
    /*
     * The reason there is no foreign key in either direction. A run has to stay a record of what it
     * was actually given, so using a kit copies its document — and a gamemaster tidying their library
     * months later must not be able to reach into a game that has been played since.
     */
    $game = Game::factory()->create();

    seatPlayers($game, 1);
    $gamemaster = withAcceptedPlanets($game);

    $kitTemplate = kitTemplateFor($gamemaster, seed: 77, name: 'Used once');

    $this->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'assets']),
        ['seed' => 5, 'kit_source' => 'saved', 'kit_template_id' => $kitTemplate->id],
    )->assertSessionHasNoErrors();

    $run = $game->fresh()?->generationRuns()->where('stage', 'assets')->sole();

    expect($run?->kit['entities'])->toBe($kitTemplate->document['entities']);

    $this->actingAs($gamemaster)->delete(route('gamemaster.kit-templates.destroy', $kitTemplate));

    expect($run?->fresh()?->kit['entities'])->toBe($kitTemplate->document['entities']);
});
