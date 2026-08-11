# Project rules index

Rules are grouped by area. Read every file whose globs cover the paths you are about to touch,
**before** you plan or edit. Entries are reason-first: the rule, then *why*, so a later agent does
not undo it.

| Globs | Rule file |
| --- | --- |
| `**` | [general.md](general.md) — verification gate, env parsing, tooling levels |
| `app/**` | [php.md](php.md) — PHP and Laravel conventions |
| `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/**`, `app/Concerns/*ValidationRules.php`, `resources/js/pages/auth/**`, `tests/Feature/Auth/**` | [auth.md](auth.md) — Fortify surface; **registration is deliberately absent** |
| `resources/js/**` | [frontend.md](frontend.md) — Inertia + Svelte 5 patterns, Wayfinder |
| `database/seeders/**` | [seeders.md](seeders.md) — seeding conventions |

Boost also ships framework/package guidance in `.ai/rules/boost/` when that directory exists.
Beyond a glob match, run `grep -rin '<keyword>' .ai/rules` — a path match alone misses
cross-cutting rules.

Record new durable rules here (or via Boost `record-rule`) rather than in personal/session memory:
only `.ai/rules` is shared with the team and survives in the repo.
