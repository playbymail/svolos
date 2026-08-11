# Seeders

Globs: `database/seeders/**`

There are two seeders: `DatabaseSeeder`, the manifest `php artisan db:seed` runs, and
`DevelopmentUserSeeder`, the six known accounts a human signs in with locally.

## `DatabaseSeeder` is a manifest and creates nothing itself

It calls seeders and never writes a row. Every seeder it lists decides for itself whether it is
allowed to run in the current environment, so that decision sits next to the data it protects rather
than in a list an edit can quietly drop an entry from.

The starter kit's `test@example.com` account used to be created here, unguarded. It is **gone**, not
moved: `DevelopmentUserSeeder` supersedes it with six named accounts whose credentials come from
helpers, and a well-known address holding the factory's shared `password` is exactly the account the
guard below exists to prevent — leaving it in `DatabaseSeeder` would have meant one
`php artisan db:seed` on a deployed installation created it anyway. Do not re-add it. Nothing
depended on the seeder creating it: the tests that mention the address (`UserRoleTest`,
`ProfileUpdateTest`, `RegistrationDisabledTest`) pass it as their own request input or factory
attribute, and no test seeds.

## A seeder with known credentials guards its own environment, and never overwrites

Both rules live in `DevelopmentUserSeeder` and both are load-bearing:

- **Return early unless `app()->environment(['local', 'testing'])`.** The passwords are published in
  the seeder's own source, so an installation that is not a development one must end up with *zero*
  accounts from it. The guard is the first thing `run()` does, and it is in the dev seeder rather
  than in `DatabaseSeeder` so that `php artisan db:seed --class=DevelopmentUserSeeder` is covered
  too.
- **Skip an account that already exists; do not update it.** A local database gets used — one of the
  six gets renamed, promoted with `app:create-admin`, given two-factor, seated in a game — and
  re-running the seeder must undo none of it. So it checks the address and `continue`s, filling in
  only what is missing. `firstOrCreate` would be fine; `updateOrCreate` would not, and neither would
  any form of truncate-then-reseed.

The **email address is the identity**, because that is what the helpers promise. Renaming or
promoting an account therefore preserves it, while changing an account's *email* frees the address
for the next run to create afresh — that is the intended reading of "the six addresses exist", not a
bug.

## The credentials are only ever named through the static helpers

`DevelopmentUserSeeder::email(int)` and `::password(int)` are the public surface:
`user1@example.com` … `user6@example.com` with `password1` … `password6`, and
`DevelopmentUserSeeder::ACCOUNTS` for how many there are. Tests, docs and console output call the
helpers instead of writing an address out, so the scheme lives in one place and no test can keep
passing against an account that moved. Both helpers **throw** `InvalidArgumentException` outside
`1..ACCOUNTS` rather than returning `user7@example.com` — an address nothing seeds would surface as
an unexplained failed sign-in instead of as the off-by-one it is.

`example.com` is deliberate: RFC 2606 reserves it, so no address minted here resolves anywhere or
can reach a mailbox somebody else owns. The passwords clear `Password::default()`, which is
`min(8)` outside production (`AppServiceProvider`), so the accounts work through password
confirmation and the settings screens as well as through login.

## Seeders build data through factories, and must survive repeated `migrate:fresh --seed`

- Go through model factories and their named states — never raw `Model::create([...])` arrays and
  never raw SQL — so factory defaults stay the single source of truth. `DevelopmentUserSeeder`
  passes only `name`, `email` and `password`; the verified timestamp and the `member` role come from
  `UserFactory`, and `password` is handed over in **plain text** because the `hashed` cast on `User`
  hashes it on the way in (it skips a value that is already a hash).
- Never assign `role` in a seeder. `role` is not fillable and only three places set it — see
  [roles.md](roles.md). Development accounts are plain members on purpose: promoting one by hand is
  worth exercising.
- `php artisan migrate:fresh --seed` must work every time, and so must `php artisan db:seed` twice
  in a row against the same database.
- **Anything that only exists to make local development pleasant gets its own seeder**, not a branch
  inside `DatabaseSeeder`, so production seeding cannot pick it up by accident.

## Testing a seeder

`tests/Feature/DevelopmentUserSeederTest.php`. Two traps, both about tests that pass while proving
nothing:

- `config(['app.env' => 'production'])` does **not** change what `app()->environment()` returns.
  `LoadConfiguration` writes the container's `env` binding once during bootstrap and never reads the
  config value again. Use `app()->detectEnvironment(fn () => 'production')`, which is the framework's
  own API for writing that binding, and assert `app()->environment()` afterwards so the test cannot
  silently keep running in `testing` — where the guard is *supposed* to allow seeding.
- `$this->seed(...)` and `php artisan db:seed` go through `ConfirmableTrait`, which **cancels the
  command in `production`** before the seeder is ever constructed. A production test using it would
  pass with the environment guard deleted. Drive the guard with
  `Artisan::call('db:seed', ['--class' => ..., '--force' => true])` and assert the exit code is `0`,
  so the confirmation layer is out of the way and the seeder's own `return` is the only thing left
  that can decline.

Both mutations were checked: deleting the guard fails four tests, and deleting the skip-existing
check fails the two re-run tests.
