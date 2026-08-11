<?php

use App\Models\Invitation;
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
