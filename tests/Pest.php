<?php

use App\Enums\GenerationStage;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
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
