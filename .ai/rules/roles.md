# Roles and the administrator boundary

Globs: `app/Enums/UserRole.php`, `app/Enums/GameRole.php`, `app/Models/User.php`,
`app/Http/Middleware/EnsureUserIsAdmin.php`, `app/Console/Commands/CreateAdmin.php`, `routes/web.php`,
`resources/js/pages/admin/**`

## `UserRole` and `GameRole` are two unrelated systems — never unify them

`App\Enums\UserRole` (`admin` | `member`) is an **application** role. `admin` is the only thing that
grants access to `/admin`, and it is a plain indexed string column on `users`.

`App\Enums\GameRole` (`player` | `gamemaster`) is a **game** role, scoped to a single game through the
`game_seats` row that joins a user to it. It carries **zero** application permissions: a gamemaster
seat does not make anyone an administrator of anything, and an administrator is not automatically a
gamemaster of any game. The enum's own doc block says so, which is where somebody adding a case will
read it. See [games.md](games.md) for the rest of the seat rules.

They look similar enough to invite a merge. Do not merge them:

- no shared `roles` or `model_has_roles` table, no polymorphic or package-based role system, no
  common `Role` enum or `HasRoles` trait meant to serve both;
- `EnsureUserIsAdmin` must never consult a game or a seat, and game authorisation must never consult
  `users.role`;
- an authorisation check reads exactly one of the two. If a check seems to need both, it is two
  checks.

The reason is a security one, not a taxonomy one. The two have different blast radii: a game role is
handed out by whoever runs a game, to anyone they invite, and is meant to be cheap to grant. An
application role reaches every account in the installation. Anything that lets the first imply the
second turns "let me run a game" into a privilege escalation path.

`tests/Feature/Admin/GameRoleSeparationTest.php` is the file that holds the line. The test that earns
its place is the escalation direction: a **member holding a gamemaster seat at the very game the route
addresses** is refused on every game admin route, on the neighbouring admin screens, and by the
middleware mounted on a bare route. It sweeps the route collection rather than listing routes, so a
game admin route added later is covered without anybody adding a case, and it posts a payload that
*would* have succeeded for an administrator so a 403 is never a validation failure in disguise. It also
reads `EnsureUserIsAdmin` and `GameSeatController` **with comments stripped** and asserts neither
mentions the other system — a behavioural test cannot see a check that is present but currently
redundant, and stripping comments is what lets the prose keep explaining the boundary without reading
as a breach of it. The two enums are additionally asserted to share no case values, so neither can be
passed where the other is expected and match by accident.

## `role` is not mass-assignable, and `#[Fillable]` is where that is enforced

`User` uses Laravel 13 attribute configuration — `#[Fillable(['name', 'email', 'password'])]` above
the class, **not** a `$fillable` array property. Keeping `role` out of that list is the boundary
between a member and an administrator:

- `ProfileController::update()` calls `$user->fill($request->validated())`;
- invitation acceptance creates accounts through `App\Actions\Fortify\CreateNewUser`.

If `role` were fillable, either could promote an account by posting `role=admin`.
`ProfileUpdateRequest::rules()` only whitelisting `name` and `email` is a *second* layer, not a
substitute — the model-level boundary is pinned directly in `tests/Feature/UserRoleTest.php`
(`the role attribute cannot be filled`, `the role attribute is absent from the fillable attributes`),
because a test that only posts to the endpoint still passes when `role` becomes fillable and would
therefore guard nothing. The endpoint test asserts both layers for the same reason.

Anything that legitimately needs to set the role assigns it explicitly:
`$user->role = UserRole::Admin`. That is `app:create-admin`, invitation acceptance
([invitations.md](invitations.md)), and `Admin\UserController::updateRole()` — the accounts screen,
which is the only place a role changes *after* an account exists ([sessions.md](sessions.md)).

The default lives in two places on purpose: the column default in
`..._add_role_to_users_table.php`, and `User::$attributes` so an unsaved `new User` already reads
back as a member instead of hitting the enum cast with a null. Change one and you must change the
other.

## `/admin` is `['auth', 'verified', 'admin']`, in that order

The `admin` alias maps to `App\Http\Middleware\EnsureUserIsAdmin` in `bootstrap/app.php`. The order
is behaviour, not decoration:

- a **guest** is redirected to the login page by `auth`, so a 403 never confirms which `/admin`
  routes exist;
- a signed-in **member** gets a 403 — they are authenticated, so a login page would be a dead end;
- the middleware itself still fails closed (`$request->user()?->isAdmin() === true`) for a null user,
  in case it is ever mounted without `auth`.

Every route in the area belongs to the group in `routes/web.php`, with the `admin.` name prefix.
`tests/Feature/AdminAccessTest.php` sweeps the route collection and fails if any route named
`admin.*` is missing one of the three, so new admin screens are covered without anyone adding a case.

The sidebar hides the administration link from members (`AppSidebar.svelte` reads
`page.props.auth.user.role`). That is presentation only — the server is the boundary. Do not turn a
hidden link into the check.

## `app:create-admin` is the only way to mint an administrator

Including in production. `App\Console\Commands\CreateAdmin`:

- has **no `--password` option** and never will. The password is read with `secret()`, so it stays
  out of shell history, `ps` output and transcripts. A test asserts the option's absence.
- validates through `App\Concerns\PasswordValidationRules` and
  `App\Concerns\ProfileValidationRules` (see [auth.md](auth.md)) — never inline rules.
- is idempotent **on the account, not on the run**: an email that already belongs to an
  administrator changes nothing and exits `0`; an email that belongs to a member is promoted only
  after a confirmation prompt, and declining exits **non-zero** so a provisioning script can tell
  "already done" from "not done".
- marks a **newly created** administrator's email verified — a shell on the server is a stronger
  proof of control than clicking a mailed link, and there is no mailbox to send to yet. It
  deliberately leaves a **promoted** account's verification state and password alone: promoting
  someone is not a reason to take their password away or to vouch for their mailbox.
- validates the email address *after* the account lookup, because `emailRules()` requires uniqueness
  and an account being promoted is by definition not unique.

Non-interactive runs fail closed: `ask()` returns null (validation then rejects the empty values) and
`confirm()` returns its `false` default (the promotion aborts).

## The `email_verified_at` backfill migration runs unconditionally on purpose

`..._backfill_email_verified_at_for_existing_users.php` is a single
`UPDATE users SET email_verified_at = now() WHERE email_verified_at IS NULL`. It exists because
`User` implements `MustVerifyEmail`, so a null column really does block an account from `/admin` —
and accounts predating the invitation-only flow have a null that means "never asked", not "asked and
refused".

Accounts created since are **not** all verified at source, and this is the one place that used to say
otherwise: `app:create-admin` verifies the administrator it creates (a shell on the server is a
stronger claim than a clicked link, and there is no mailbox to send to yet), but **invitation
acceptance deliberately leaves the address unverified** — clicking a mailed link proves somebody read
the mailbox, not that the person filling in the form controls it. See [invitations.md](invitations.md).
The backfill is therefore about the accounts that predate the flow, not a general guarantee that
every account arrives verified.

Running it unconditionally is safe and is why there is no guard: on an empty `users` table — fresh
install, `migrate:fresh`, every test run — it matches no rows, and it can never clear a timestamp
that is already set. It writes through the query builder rather than the model so later changes to
`User` cannot change what a historical migration does. `down()` is deliberately a no-op: which rows
were unverified beforehand is not recorded, so the only available reversal would unverify everyone.
