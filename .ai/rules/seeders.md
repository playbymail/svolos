# Seeders

Globs: `database/seeders/**`

Nothing beyond the framework default is settled yet — `DatabaseSeeder` still creates the single
`test@example.com` user from the starter kit. A later task fills this in.

When it does, the conventions to hold to:

- Seeders build data through model factories and their named states, never with raw
  `Model::create([...])` arrays or raw SQL, so factory defaults stay the single source of truth.
- Seeders must be idempotent enough to survive `php artisan migrate:fresh --seed` repeatedly.
- Anything that only exists to make local development pleasant belongs in its own dedicated seeder
  rather than in `DatabaseSeeder`, so production seeding never picks it up by accident.

Add the concrete rules here when that task lands — do not invent them ahead of time.
