<?php

use App\Enums\GameRole;
use App\Enums\GenerationStage;
use App\Generation\KitGenerator;
use App\Generation\StartingUnits;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\HomeStellium;
use App\Models\Invitation;
use App\Models\KitTemplate;
use App\Models\Location;
use App\Models\Stellium;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Read a class's source with every comment and doc block removed.
 *
 * The role-separation rules are about what the *code* consults, not about what the prose explains — and
 * the prose has to be free to name both systems in order to say they are unrelated. Tokenising is what
 * keeps a documentation comment from reading as a violation, and vice versa: a real reference cannot hide
 * in a comment either.
 *
 * It lives here rather than in one test file because both sides of the boundary assert it — the admin
 * middleware must mention no seat (`tests/Feature/Admin/GameRoleSeparationTest.php`) and the gamemaster
 * middleware must mention no application role (`tests/Feature/Gamemaster/GameManagementTest.php`) — and a
 * helper declared in a test file is only loaded when that file is, which a `--filter` run need not do.
 *
 * @param  class-string  $class
 */
function executableSourceOf(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    expect($file)->toBeString();

    return collect(token_get_all((string) file_get_contents((string) $file)))
        ->reject(fn (array|string $token): bool => is_array($token)
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (array|string $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/**
 * Create a member holding an **active** gamemaster seat at the given game.
 *
 * The one thing that opens `/gamemaster/games/{game}`, and the setup of nearly every test in
 * `tests/Feature/Gamemaster`. A plain member on purpose: a helper that quietly made every gamemaster
 * an administrator would make the whole area's authorisation tests pass for the wrong reason.
 *
 * @param  array<string, mixed>  $attributes
 */
function gamemasterOf(Game $game, array $attributes = []): User
{
    $gamemaster = User::factory()->create($attributes);

    GameSeat::factory()->for($game)->for($gamemaster)->gamemaster()->create();

    return $gamemaster;
}

/**
 * Give a game an accepted run for every generation stage, so it counts as fully generated.
 *
 * A game cannot become `Active` until its world exists (see `GameValidationRules::gameStatusRules()`),
 * which makes this the setup for every test about a game that is *being played* rather than being
 * built. The runs are made by factory rather than by running the generators: what is being asserted is
 * the gate, and generating a hundred locations to satisfy it would make dozens of tests slower for
 * nothing. Tests about the generators themselves run the real thing.
 *
 * Lives here rather than in a test file because both areas' tests need it, and a helper declared in a
 * test file is only loaded when that file is — which a `--filter` run need not do.
 */
function withCompletedGeneration(Game $game): Game
{
    foreach (GenerationStage::cases() as $stage) {
        GenerationRun::factory()->for($game)->stage($stage)->accepted()->create();
    }

    return $game->load('generationRuns');
}

/**
 * Give every active player at a game somewhere to begin, so it can become `GameStatus::Active`.
 *
 * The companion to `withCompletedGeneration()` above, and needed *beside* it rather than folded into
 * it: that helper fabricates accepted runs with **no rows**, so a game it prepares is "generated" in
 * the sense the stage machine cares about while still having nowhere for anybody to start.
 * `GameValidationRules::gameStatusRules()` checks both, and this is the second half.
 *
 * The locations are spaced 10 apart in `x` so they satisfy any sane minimum separation, and each gets
 * a single-star stellium — not because this helper places anything (it writes the homes directly), but
 * so the rows it leaves behind describe a world the real generator could have produced.
 *
 * Reuses the game's accepted home stellia run when it already has one, so calling this after
 * `withCompletedGeneration()` does not leave two standing runs for the same stage — which
 * `Game::generationRunFor()` would then have to choose between.
 */
function withPlacedHomes(Game $game): Game
{
    $run = $game->generationRuns()
        ->where('stage', GenerationStage::HomeStellia)
        ->whereNull('superseded_at')
        ->first()
        ?? GenerationRun::factory()->for($game)->stage(GenerationStage::HomeStellia)->accepted()->create();

    $column = 0;

    foreach ($game->activeSeats()->where('role', GameRole::Player)->get() as $seat) {
        $location = Location::factory()
            ->for($game)
            ->create(['generation_run_id' => $run->id, 'x' => $column, 'y' => 0, 'z' => 0]);

        Stellium::factory()
            ->for($location)
            ->withStars(1)
            ->create(['generation_run_id' => $run->id]);

        HomeStellium::factory()->create([
            'generation_run_id' => $run->id,
            'game_seat_id' => $seat->id,
            'location_id' => $location->id,
        ]);

        $column += 10;
    }

    return $game->load('generationRuns');
}

/**
 * Create an invitation together with the plain-text token that would have been emailed.
 *
 * `invitations.token` stores a sha256 hash, so nothing can recover the plain text from a row — not
 * even a factory. A test that needs to follow the link therefore has to mint the token itself and
 * store the hash of it, which is exactly what `App\Actions\Invitations\IssueInvitation` does.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Invitation, 1: string} the invitation, and the token that reaches the mailbox
 */
function invitationWithToken(array $attributes = []): array
{
    $token = Invitation::generateToken();

    $invitation = Invitation::factory()->create([
        ...$attributes,
        'token' => Invitation::hashToken($token),
    ]);

    return [$invitation, $token];
}

/**
 * Read the invitation token out of the most recent email the application actually sent.
 *
 * `MAIL_MAILER=array` in `phpunit.xml`, so this reads the real outgoing message rather than a faked
 * notification: the assertion is that the token reaches the mailbox, not that a method was called.
 * The HTML body is read unencoded, because the transport's raw form is quoted-printable and would
 * fold a 64-character token across lines.
 */
function invitationTokenFromLastEmail(): string
{
    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    $sent = $transport->messages()->last();

    expect($sent)->not->toBeNull();

    $body = (string) $sent->getOriginalMessage()->getHtmlBody();

    expect($body)->toMatch('#/invitations/[A-Za-z0-9]{64}#');

    preg_match('#/invitations/([A-Za-z0-9]{64})#', $body, $matches);

    return $matches[1];
}

/*
|--------------------------------------------------------------------------
| Walking a game through its generation stages
|--------------------------------------------------------------------------
|
| The stages build on one another, so a test about any one of them has to run every stage before it —
| and these run the **real** generators, because a fabricated cluster would not tell us whether the
| rules the later stages draw under can actually be satisfied. (`withCompletedGeneration()` above is
| the opposite tool, for tests about a game that is already built.)
|
| They live here rather than in a test file for the reason `withCompletedGeneration()` does: three
| files in two directories need them now, and a helper declared in a test file is only loaded when that
| file is — which a `--filter` run need not do.
|
*/

/**
 * Generate a stage as a gamemaster, and hand back the response.
 *
 * `$traveler` is posted only when it is set, because that is what an unticked checkbox does — sending
 * `traveler=0` would exercise a shape the screen never produces.
 */
function generateStage(User $gamemaster, Game $game, GenerationStage $stage, int $seed, bool $traveler = false): TestResponse
{
    return test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => $stage->value]),
        $traveler ? ['seed' => $seed, 'traveler' => '1'] : ['seed' => $seed],
    );
}

/**
 * Build a kit document as a file, the way a gamemaster's browser sends one.
 *
 * Drawn rather than written as a literal, for the reason `KitTemplateFactory` draws one: a hand-kept
 * fixture would need updating every time the catalogue gains a kind, and would quietly stop
 * describing every kind a game opens with — which is the one thing `Kit` refuses hardest.
 *
 * `$mutate` is how a test breaks exactly one thing about an otherwise valid document.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
 */
function kitDocumentFile(?callable $mutate = null, int $seed = 4242, string $name = 'kit.json'): UploadedFile
{
    $document = (new KitGenerator(new StartingUnits))->generate($seed)->toArray();

    if ($mutate !== null) {
        $document = $mutate($document);
    }

    return UploadedFile::fake()->createWithContent($name, (string) json_encode($document));
}

/**
 * Save a kit in somebody's library.
 */
function kitTemplateFor(User $owner, int $seed = 4242, string $name = 'Lean start'): KitTemplate
{
    return KitTemplate::factory()->for($owner)->create([
        'name' => $name,
        'seed' => $seed,
        'document' => (new KitGenerator(new StartingUnits))->generate($seed)->toArray(),
    ]);
}

/**
 * Ask the gamemaster's game screen for one location's system, the way the browser does.
 *
 * `locationDetail` is an optional prop, so it only arrives on a partial reload — and the response is
 * JSON with no view at all, which is why the tests that use this assert on `props.*` paths rather than
 * through `assertInertia`.
 *
 * The version header has to be the real one: Inertia answers a mismatched version with a 409 telling
 * the client to reload, so a hardcoded value here would fail every one of these tests for a reason
 * that has nothing to do with what they assert.
 *
 * It lives here rather than in a test file because two files need it now — the planets are reviewed
 * through this prop and so is the opening position — and a helper declared in a test file is only
 * loaded when that file is, which a `--filter` run need not do.
 */
function reloadLocationDetail(User $gamemaster, Game $game, int $locationId): TestResponse
{
    return test()->actingAs($gamemaster)->get(
        route('gamemaster.games.show', ['game' => $game, 'location' => $locationId]),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'gamemaster/games/Show',
            'X-Inertia-Partial-Data' => 'locationDetail',
        ],
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

/**
 * Take a game as far as accepted stelliums, and hand back its gamemaster.
 *
 * Where the stars come from, and so the setup behind every stage after it.
 */
function withAcceptedStelliums(Game $game, int $seed = 7): User
{
    $gamemaster = withAcceptedCluster($game);

    generateStage($gamemaster, $game, GenerationStage::Stelliums, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'stelliums'])
    );

    return $gamemaster;
}

/**
 * Take a game as far as an accepted home template, and hand back its gamemaster.
 *
 * Generated rather than uploaded, because these tests are about the stages around it rather than about
 * the document — `HomeTemplateTest` is where the uploaded half is exercised.
 *
 * The setup for every home stellia and planets test: both stages read the template, and neither will
 * run until it has been accepted.
 */
function withAcceptedTemplate(Game $game, int $seed = 11): User
{
    $gamemaster = withAcceptedStelliums($game);

    generateTemplate($gamemaster, $game, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia_template'])
    );

    return $gamemaster;
}

/**
 * Settle a game's home template, either by drawing one or by uploading a document.
 *
 * The two ways the stage can be run, behind one helper because every caller that does not care which
 * wants the drawn one. `generate_template` is posted only when no file is — the screen's checkbox and
 * its file input are alternatives, and posting both would exercise a shape it never produces.
 */
function generateTemplate(User $gamemaster, Game $game, int $seed, ?UploadedFile $document = null): TestResponse
{
    return test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.store', ['game' => $game, 'stage' => 'home_stellia_template']),
        $document === null
            ? ['seed' => $seed, 'generate_template' => '1']
            : ['seed' => $seed, 'template' => $document],
    );
}

/**
 * Write a home template document the way a gamemaster would hand one over.
 *
 * Nine planets by default, with the third as the home world, so an uploaded template can be compared
 * against a generated one without the shape itself being the difference.
 *
 * @param  array<int, array<string, mixed>>|null  $planets
 */
function templateDocument(?array $planets = null, string $name = 'homeworld.json'): UploadedFile
{
    $planets ??= [
        ['ordinal' => 1, 'type' => 'rocky', 'habitability' => 3],
        ['ordinal' => 2, 'type' => 'rocky', 'habitability' => 8],
        ['ordinal' => 3, 'type' => 'rocky', 'habitability' => 25, 'home' => true, 'fuel' => 5, 'metals' => 5, 'minerals' => 5],
        ['ordinal' => 4, 'type' => 'asteroids', 'habitability' => 0],
        ['ordinal' => 5, 'type' => 'gas_giant', 'habitability' => 2],
        ['ordinal' => 6, 'type' => 'icy', 'habitability' => 4],
        ['ordinal' => 7, 'type' => 'icy', 'habitability' => 1],
        ['ordinal' => 8, 'type' => 'asteroids', 'habitability' => 0],
        ['ordinal' => 9, 'type' => 'icy', 'habitability' => 6],
    ];

    return UploadedFile::fake()->createWithContent(
        $name,
        (string) json_encode(['planets' => $planets]),
    );
}

/**
 * Take a game as far as an accepted arrangement of homes, and hand back its gamemaster.
 *
 * The setup for every planets test, since the planets stage is now last. Most of these games have
 * nobody seated, which is an ordinary state rather than a shortcut: the stage places no homes, accepts
 * cleanly, and leaves every star to be drawn — so the assertions about what the planet generator
 * produces are about the drawn path and nothing else.
 */
function withAcceptedHomeStellia(Game $game, int $seed = 3): User
{
    $gamemaster = withAcceptedTemplate($game);

    generateStage($gamemaster, $game, GenerationStage::HomeStellia, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'home_stellia'])
    );

    return $gamemaster;
}

/**
 * Take a game as far as accepted planets, and hand back its gamemaster.
 *
 * The setup for every units test, and the first link in this chain that walks the *whole* world into
 * existence: after this a game has somewhere for everybody to stand.
 */
function withAcceptedPlanets(Game $game, int $seed = 5): User
{
    $gamemaster = withAcceptedHomeStellia($game);

    generateStage($gamemaster, $game, GenerationStage::Planets, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'planets'])
    );

    return $gamemaster;
}

/**
 * Take a game all the way to an accepted opening position, and hand back its gamemaster.
 *
 * The end of the chain: a game past this is fully generated, and `Game::isGenerationComplete()` is
 * true of it.
 */
function withAcceptedUnits(Game $game, int $seed = 9): User
{
    $gamemaster = withAcceptedPlanets($game);

    generateStage($gamemaster, $game, GenerationStage::Assets, $seed);

    test()->actingAs($gamemaster)->post(
        route('gamemaster.games.generation.accept', ['game' => $game, 'stage' => 'assets'])
    );

    return $gamemaster;
}

/**
 * Seat some players at a game, in a fixed order.
 *
 * @return array<int, GameSeat>
 */
function seatPlayers(Game $game, int $count): array
{
    return array_map(
        fn (): GameSeat => GameSeat::factory()->for($game)->for(User::factory())->create(),
        range(1, $count),
    );
}
