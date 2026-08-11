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
6. `artisan test` (Pest suite)

`composer test` deliberately still re-runs Pint and PHPStan before the suite so a bare
`composer test` is not weaker than the gate. `ci:check` calls `artisan test` directly instead of
`@test` so nothing runs twice. Do not drop a step to make the gate green.

## `.env` values containing spaces must be quoted

`APP_NAME="Epimethean Challenge"` — with the quotes. An unquoted value containing a space makes the
dotenv parser throw, and because the Wayfinder Vite plugin boots the Laravel app to enumerate
routes, that throw fails `npm run build` (and therefore the whole gate) with an error that does not
mention the env file.

## PHPStan runs at level 7 with an explicit memory limit

`composer types:check` is `phpstan analyse --memory-limit=1G`. The limit is explicit because a stock
PHP CLI with no `php.ini` defaults to `memory_limit=128M`, which crashes the PHPStan parallel workers
with "PHPStan process crashed because it reached configured PHP memory limit" — a failure that looks
like an analysis error but is not one.

Level 7 is the highest level that passes with **zero** errors and **zero** ignores or baseline
entries. Level 8 reports 10 `method.nonObject` / `property.nonObject` errors, all of them the same
thing: `$request->user()` is typed `?User`, and the starter-kit auth/settings code calls straight
through it. The blocked files are `app/Http/Controllers/Settings/ProfileController.php`,
`app/Http/Controllers/Settings/SecurityController.php`, and
`app/Http/Requests/Settings/ProfileUpdateRequest.php`. Raising the level is worth doing once those
controllers are rewritten, but only by fixing the null-safety at the call sites — never by adding a
baseline file, `ignoreErrors`, `@phpstan-ignore` comments, inline `@var` overrides, or
`treatPhpDocTypesAsCertain: false`. A level that only "passes" because of suppressions is not a
higher level.

## Databases

SQLite everywhere: `DB_CONNECTION=sqlite` locally, `:memory:` in `phpunit.xml`. Sessions use the
`database` driver in `.env.example` and `array` in tests. The `sessions` table is created inside
`database/migrations/0001_01_01_000000_create_users_table.php` (not a separate migration) — a later
feature reads that table directly, so leave it where it is.
