# Config

Globs: `config/**`

## A blank value in `.env.example` is the empty string, not null

`MAILGUN_DOMAIN=` in `.env.example` parses to `''`, not null. This bites only on a fresh clone or
CI, where `composer setup` copies `.env.example` to `.env`; a developer whose own `.env` simply
omits the key gets null from `env()` and never sees it. A test asserting `toBeNull()` on such a
value therefore passes locally and fails in CI — which is exactly what happened to
`tests/Feature/MailTransportTest.php`, and it failed every push to `main` for as long as nobody
looked.

`config/services.php` coerces with `env('MAILGUN_DOMAIN') ?: null` so that both spellings of
"unconfigured" agree, and an empty domain cannot build a transport that only fails later against
Mailgun. Do the same for any new credential left blank in `.env.example`, and assert on the config
value rather than on `env()` directly.

The general lesson is in [general.md](general.md)'s verification gate: a check that only ever runs
against your own `.env` is not the same check CI runs. To reproduce a CI-only failure, copy
`.env.example` over `.env`, `php artisan key:generate`, then `php artisan config:clear` — and put
your real `.env` back afterwards.
