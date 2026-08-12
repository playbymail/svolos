<?php

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport;

/*
|--------------------------------------------------------------------------
| Mail transport
|--------------------------------------------------------------------------
|
| Invitations are the only mail this application sends, and they are the only way an account
| is created, so a broken transport means nobody can be let in. Two things are asserted:
|
| - a real transport is available and wired to config — `mailgun`, via the
|   symfony/mailgun-mailer bridge (which needs symfony/http-client to actually build);
| - the default mailer is still `log`, and no credentials are committed. A fresh clone must
|   run, and its test suite must pass, with no account at a mail provider.
|
| `phpunit.xml` sets MAIL_MAILER=array for the suite, so `config('mail.default')` reads
| `array` here rather than the deployed default. The fallback in the config file is therefore
| asserted against the file, in the same spirit as AppearanceTest asserting on the rendered
| Blade template.
|
*/

test('the mailgun mailer is configured and its transport can be built', function () {
    expect(config('mail.mailers.mailgun.transport'))->toBe('mailgun');

    /*
     * Credentials are supplied here, not in the repository: this proves the bridge is installed and
     * the config keys line up, without a real account. Building the transport makes no network call.
     */
    config([
        'services.mailgun.domain' => 'mail.example.test',
        'services.mailgun.secret' => 'key-not-a-real-secret',
        'services.mailgun.endpoint' => 'api.mailgun.net',
        'services.mailgun.scheme' => 'https',
    ]);

    /*
     * `MailgunHttpTransport` rather than `MailgunApiTransport` because `services.mailgun.scheme` is
     * `https`: Laravel builds the DSN as `mailgun+https`, which is the configuration Laravel's own
     * documentation ships. Both talk to Mailgun over HTTPS; this one posts the assembled MIME
     * message, which keeps attachments and headers exactly as the mailer built them.
     */
    expect(Mail::mailer('mailgun')->getSymfonyTransport())->toBeInstanceOf(MailgunHttpTransport::class);
});

test('the default mailer falls back to log and no mail credentials are committed', function () {
    expect(file_get_contents(config_path('mail.php')))
        ->toContain("'default' => env('MAIL_MAILER', 'log')");

    expect(config('services.mailgun.domain'))->toBeNull()
        ->and(config('services.mailgun.secret'))->toBeNull();

    expect(file_get_contents(base_path('.env.example')))
        ->toContain("\nMAIL_MAILER=log\n")
        ->toContain("\nMAILGUN_DOMAIN=\n")
        ->toContain("\nMAILGUN_SECRET=\n");
});

/*
 * The assertion above is only meaningful when the credentials really are absent from the
 * environment, which is how a developer's own `.env` usually looks — the MAILGUN_* keys are simply
 * not in it, so `env()` returns null whatever `config/services.php` does. A fresh clone is the
 * opposite case: `composer setup` copies `.env.example`, whose `MAILGUN_DOMAIN=` is parsed as the
 * empty string. This pins the coercion that makes both spellings of "unconfigured" agree, so the
 * suite cannot pass locally while failing on a clean checkout.
 */
test('a blank mailgun credential in the environment reads back as unconfigured', function () {
    $restore = array_intersect_key($_ENV + $_SERVER, ['MAILGUN_DOMAIN' => null, 'MAILGUN_SECRET' => null]);

    $_ENV['MAILGUN_DOMAIN'] = $_SERVER['MAILGUN_DOMAIN'] = '';
    $_ENV['MAILGUN_SECRET'] = $_SERVER['MAILGUN_SECRET'] = '';

    try {
        $services = require config_path('services.php');

        expect($services['mailgun']['domain'])->toBeNull()
            ->and($services['mailgun']['secret'])->toBeNull();
    } finally {
        foreach (['MAILGUN_DOMAIN', 'MAILGUN_SECRET'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);

            if (array_key_exists($key, $restore)) {
                $_ENV[$key] = $_SERVER[$key] = $restore[$key];
            }
        }
    }
});
