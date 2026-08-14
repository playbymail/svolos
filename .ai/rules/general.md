# General rules

Globs: `**`

## The verification gate is `npm run build` **then** `composer ci:check`

Run them in that order. `npm run build` is not optional before the test suite: Inertia pages are
resolved through the Vite manifest at `public/build/manifest.json`, so a page that is missing from
the manifest makes the whole request fail with
`Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest`, and the test asserting
that page 500s instead of failing with a useful message. `public/build/` is gitignored, so a fresh
clone or CI runner has no manifest until the build runs. `.github/workflows/tests.yml` encodes this:
`composer setup` (which ends in `npm run build`) runs before `composer ci:check`.

`composer ci:check` runs each check exactly once, in this order:

1. `pint --parallel --test` (PHP formatting)
2. `eslint .` (JS/TS/Svelte lint)
3. `prettier --check resources/` (JS/TS/Svelte/CSS formatting)
4. `phpstan analyse` (static analysis)
5. `svelte-check` (Svelte/TS type checking)
6. `vitest run` (front-end unit tests)
7. `artisan test` (Pest suite)

`composer test` deliberately still re-runs Pint and PHPStan before the suite so a bare
`composer test` is not weaker than the gate. `ci:check` calls `artisan test` directly instead of
`@test` so nothing runs twice. Do not drop a step to make the gate green.

## Vitest covers the *pure* front end, and nothing else

There are two test runners and they do not overlap. **Pest** answers everything that reaches the
server, including what a screen renders — Feature tests over Inertia payloads, which is how component
behaviour is covered here. **Vitest** covers front-end modules that are arithmetic and nothing else:
today `resources/js/lib/cluster-hex.ts`, the hex map's geometry.

The line is worth holding, because the reason for the second runner is narrow. A parity bug in
`toCube()` still draws a hundred systems in a hundred plausible hexes and still returns a distance for
every pair; the numbers are just wrong, and no screenshot and no payload assertion shows it. That is
the same argument `GeneratorPurityTest` makes about `shuffle()` — some failures are invisible to
behaviour. It is *not* an argument for asserting markup, and no DOM was installed: there is no
`jsdom`, no `@testing-library`, and adding them is a new decision rather than an extension of this one.

Three mechanical points:

- **Tests are co-located**, `resources/js/**/*.test.ts`, so `prettier --check resources/` and
  `tsconfig.json`'s `include` already cover them with no glob widened.
- **`vitest.config.ts` is separate from `vite.config.ts` on purpose.** That config builds the
  application, and two of its plugins fight a test run: `laravel()` performs an environment check —
  which is why `vite.config.ts` already carries a `LARAVEL_BYPASS_ENV_CHECK` hack for `svelte-check` —
  and `wayfinder()` regenerates route types on `buildStart`, seconds spent per run producing something
  no test reads. The `@` alias is therefore **declared** in the test config rather than inherited; it
  comes from `tsconfig.json`'s `paths`, and no plugin sets it.
- **Import from `vitest` explicitly** (`import { describe, expect, it } from 'vitest'`) rather than
  enabling globals, so ESLint needs no extra environment. `vitest.config.ts` is in the ESLint ignore
  list beside `vite.config.ts`, for the same reason.

## Run `php artisan view:clear` when verifying a Blade edit locally

`tests/Feature/AppearanceTest.php` asserts on the raw HTML of `resources/views/app.blade.php`, and
compiled Blade views are cached in `storage/framework/views`. Editing the template and immediately
re-running those tests can be served the previously compiled version, so a change appears to have had
no effect — or worse, a mutation you made to check that a test really fails appears to be caught when
it was not exercised at all. Clear the cache between the edit and the run. CI is unaffected because
`storage/framework/views` starts empty there.

## `.env` values containing spaces must be quoted

`APP_NAME="Epimethean Challenge"` — with the quotes. An unquoted value containing a space makes the
dotenv parser throw, and because the Wayfinder Vite plugin boots the Laravel app to enumerate
routes, that throw fails `npm run build` (and therefore the whole gate) with an error that does not
mention the env file.

## PHPStan runs at level 8 with an explicit memory limit

`composer types:check` is `phpstan analyse --memory-limit=1G`. The limit is explicit because a stock
PHP CLI with no `php.ini` defaults to `memory_limit=128M`, which crashes the PHPStan parallel workers
with "PHPStan process crashed because it reached configured PHP memory limit" — a failure that looks
like an analysis error but is not one.

Level 8 (nullsafety) passes with **zero** errors and **zero** ignores or baseline entries. It used to
be level 7: the ten `method.nonObject` / `property.nonObject` errors that blocked the raise were
`$request->user()` being typed `?User` in the settings controllers, plus one genuinely nullable
`$passkey->created_at`, and all ten were fixed at the call sites — see the authenticated-user idiom in
[php.md](php.md). Do not lower the level back, and never raise or hold one with a baseline file,
`ignoreErrors`, `@phpstan-ignore` comments, inline `@var` overrides, `assert()`, or
`treatPhpDocTypesAsCertain: false`. A level that only "passes" because of suppressions is not a
higher level. `phpstan.neon` has no `ignoreErrors` section and no baseline include; keep it that way.

Level 9 (`mixed` strictness) reports 3 errors, all in the Fortify wiring
(`app/Providers/FortifyServiceProvider.php` and `config/fortify.php`, reading `mixed` out of config
and env), so it is a separate piece of work rather than a follow-on to this one.

## Databases

**SQLite runs in WAL with a busy timeout, and the two are a pair.** The default rollback journal
takes an exclusive lock for a whole write, so a second writer fails instantly with
`SQLSTATE[HY000]: General error: 5 database is locked` — a 500 rather than a wait. A concurrent burst
against `api/*` produced four of them, and every signed-in request writes `sessions` on the same
file. `journal_mode=WAL` stops readers and the writer blocking each other; `busy_timeout=5000` turns
the remaining writer-against-writer case into a short wait. WAL alone still fails the instant two
writers collide, and a busy timeout alone would make every reader queue behind a writer, so do not
keep one without the other.

Two consequences worth knowing. WAL keeps `-wal` and `-shm` files beside the database, so the
*directory* must be writable and not merely the file. And a WAL database **cannot be backed up by
copying the file** — the recent commits live in the `-wal`; `scripts/deploy.sh` uses `sqlite3
.backup`, the online backup API, which reads through it. `synchronous` is deliberately left at
SQLite's `FULL` default: `NORMAL` is the usual WAL companion and is faster, but it trades durability
across an OS crash, which is its own decision. `tests/Feature/DatabaseConfigurationTest.php` asserts
all of this against a real file database, because the suite runs on `:memory:`, which reports
`memory` whatever is asked of it.

SQLite everywhere: `DB_CONNECTION=sqlite` locally, `:memory:` in `phpunit.xml`. Sessions use the
`database` driver in `.env.example` and `array` in tests. The `sessions` table is created inside
`database/migrations/0001_01_01_000000_create_users_table.php` (not a separate migration) — the
sessions administration screen reads that table directly through `App\Models\Session`, so leave it
where it is. `sessions.user_id` deliberately has **no** foreign key, and the `array` driver in tests
means there is no session row for the current request unless one is made; both matter, and both are
in [sessions.md](sessions.md).
