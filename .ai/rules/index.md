# Project rules index

Rules are grouped by area. Read every file whose globs cover the paths you are about to touch,
**before** you plan or edit. Entries are reason-first: the rule, then *why*, so a later agent does
not undo it.

| Globs | Rule file |
| --- | --- |
| `**` | [general.md](general.md) — verification gate, env parsing, tooling levels |
| `app/**` | [php.md](php.md) — PHP and Laravel conventions |
| `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/**`, `app/Concerns/*ValidationRules.php`, `resources/js/pages/auth/**`, `tests/Feature/Auth/**` | [auth.md](auth.md) — Fortify surface; **registration is deliberately absent** |
| `app/Enums/UserRole.php`, `app/Models/User.php`, `app/Http/Middleware/EnsureUserIsAdmin.php`, `app/Console/Commands/CreateAdmin.php`, `routes/web.php`, `resources/js/pages/admin/**` | [roles.md](roles.md) — `UserRole` vs game roles (**never unify them**), the non-fillable `role` column, the `/admin` boundary, `app:create-admin` |
| `app/Models/Invitation.php`, `app/Actions/Invitations/**`, `app/Notifications/**`, `app/Enums/Invitation*.php`, `app/Http/Controllers/InvitationAcceptanceController.php`, `app/Http/Controllers/Admin/InvitationController.php`, `resources/js/pages/invitations/**`, `resources/js/pages/admin/invitations/**`, `config/mail.php`, `config/services.php` | [invitations.md](invitations.md) — **tokens are stored hashed** (so resending rotates them), `IssueInvitation`, why acceptance leaves the email **unverified**, the three failure reasons, mail transport |
| `resources/js/**`, `resources/views/app.blade.php` | [frontend.md](frontend.md) — Inertia + Svelte 5 patterns, the app shell, theme and toasts, Wayfinder |
| `database/seeders/**` | [seeders.md](seeders.md) — seeding conventions |

Boost also ships framework/package guidance in `.ai/rules/boost/` when that directory exists.
Beyond a glob match, run `grep -rin '<keyword>' .ai/rules` — a path match alone misses
cross-cutting rules.

Record new durable rules here (or via Boost `record-rule`) rather than in personal/session memory:
only `.ai/rules` is shared with the team and survives in the repo.
