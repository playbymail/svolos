# Build a Laravel + Svelte parity port of the Epimethean Challenge app

## What you are doing

Build a Laravel + Inertia **Svelte** application that reaches feature parity with an existing
Laravel + Inertia **React** application. The two codebases will be compared side by side to
evaluate the development process, so the goal is an honest, idiomatic Svelte implementation of
the same behaviour — not a mechanical transliteration of React components.

You are the **orchestrator**. You do not implement the tasks yourself. You break the work into
the numbered tasks below, hand each one to a sub-agent on its own branch, verify the result
yourself, and only then merge it to `main`.

The human will verify the finished application in a browser after every task is merged. Your job
is to make sure that when they do, it works.

---

## Orchestration protocol

### Loop

Run the tasks **strictly in order**. Never run two tasks at once — each task builds on the last.

For each task, in order:

1. **Branch.** From an up-to-date `main`, create `task/NN-slug` (e.g. `task/05-invitations`).
2. **Delegate.** Launch a sub-agent with:
   - the full text of that task section, verbatim,
   - the **Shared conventions** section below, verbatim,
   - the branch name it must work on,
   - the instruction that it must commit its work on that branch and must not touch `main`.
3. **Verify.** When the sub-agent reports completion, run the **Verification gate** yourself.
   Do not take the sub-agent's word for anything. A report of "all tests pass" is a claim to be
   checked, not a result.
4. **Reject or accept.**
   - **Fails?** Send the sub-agent back with the specific failures — the actual command output,
     the specific acceptance criterion not met. Continue the same sub-agent so it keeps its
     context rather than starting a fresh one. Re-verify from scratch after it responds.
     After three failed rounds, fix the remainder yourself and note in the merge commit what you
     had to repair.
   - **Passes?** Merge to `main` with `git merge --no-ff task/NN-slug`, delete the branch, and
     move to the next task.
5. **Record.** If the task settled a non-obvious decision or exposed a trap that would bite the
   next agent, write it down (see *Recording decisions* below) before moving on.

Do not skip a task. Do not merge a task that is partially done — an incomplete task is a
rejection, not a "mostly there". If a task turns out to be genuinely blocked, stop the loop,
finish nothing further, and report to the human what is blocked and why.

### Verification gate

Run every one of these yourself, on the task branch, before merging. All must pass:

```bash
composer install && npm install     # in case the task added dependencies
npm run build                       # REQUIRED before tests: Inertia resolves pages through the
                                    # Vite manifest, so a page missing from the manifest makes
                                    # the whole request 500 with a ViteException
composer ci:check                   # pint --test, eslint, prettier --check, svelte-check,
                                    # phpstan, and the full Pest suite
php artisan migrate:fresh --seed    # migrations actually run from empty, seeders actually work
git status --porcelain              # must be empty: no uncommitted or untracked leftovers
```

Then, by hand:

- **Read the diff.** `git diff main...HEAD`. Every acceptance criterion in the task must be
  visibly satisfied by code you can point at.
- **Read the new tests.** Confirm they assert the behaviour the task asked for. A test that
  only asserts a 200 response for a task that specified authorisation rules is a vacuous test —
  reject it. Confirm nothing was marked skipped, incomplete, or commented out.
- **Check nothing was loosened.** Diff `phpstan.neon`, `phpunit.xml`, `pint.json`, the ESLint and
  Prettier configs, and `composer.json`'s `ci:check`. A sub-agent that made the gate pass by
  weakening the gate has failed the task. Same for `@ts-ignore`, `svelte-ignore`, baseline files,
  and `--no-verify`. Any of these appearing without the task explicitly calling for it is an
  automatic rejection.
- **Check the scope.** Work belonging to a later task, or refactors of earlier merged tasks that
  the task did not ask for, is scope creep — send it back.

### Recording decisions

This project keeps settled decisions in `.ai/rules/`, grouped by the paths they apply to, with an
`index.md` mapping globs to rule files. Task 0 sets that up. Whenever a task settles something
that is not recoverable from reading the code — a trap, a deliberate omission, a constraint that
looks like a bug until you know why — append it to the matching rule file as part of that task's
branch. Keep the entries short and reason-first: what the rule is, and why, so the next agent
does not undo it.

---

## Shared conventions

Pass this section to every sub-agent verbatim.

### Stack

The reference application runs Laravel 13 / PHP 8.4, Inertia 3, Tailwind 4, Vite, Fortify,
Wayfinder, Pest 5, Pint, and Larastan. Match those major versions. The frontend is the one
deliberate divergence: **Svelte 5 with `@inertiajs/svelte`**, not React.

**Confirm every package version before you rely on its API.** `composer show --direct` for PHP,
`package.json` for JS. Do not assume an API from memory — check the installed version's docs.

### Known Svelte-side divergences from the React reference

These are where a naive port will fight you. Solve them idiomatically for Svelte rather than
emulating the React shape:

- **Page layouts.** The React app assigns a static property on the page component
  (`Dashboard.layout = { breadcrumbs: [...] }`). The Svelte adapter uses a different mechanism —
  check the installed adapter's docs and pick one approach, then apply it consistently across
  every page.
- **UI components.** The React app uses shadcn/ui on Radix. Use the Svelte analogue
  (shadcn-svelte, on Bits UI) so the comparison stays fair. If a specific component fights the
  port, hand-roll that one component rather than pulling in a second component library.
- **Icons and toasts.** React's `lucide-react` and `sonner` have Svelte equivalents. Confirm the
  current package names and versions before installing.
- **Type checking.** `svelte-check` replaces the bare `tsc --noEmit` in `types:check`. Keep TypeScript
  for everything outside `.svelte` files.
- **Wayfinder is framework-agnostic** — it emits plain TypeScript, so keep it. Configure the Vite
  plugin with `formVariants: true`, and regenerate through `npm run build` / `npm run dev` rather
  than a bare `php artisan wayfinder:generate`, which omits the form variants and breaks the
  typecheck. (The artisan flag, if you need it directly, is `--with-form`.)

### PHP conventions

- Curly braces on every control structure, even single-line bodies.
- Constructor property promotion: `public function __construct(private readonly Foo $foo) {}`.
- Explicit return types and parameter type hints on every method.
- TitleCase enum cases. Every enum that reaches the UI gets a `label(): string` method.
- PHPDoc blocks over inline comments; inline comments only for genuinely non-obvious logic.
  Array shape types in PHPDoc where the shape matters.
- Generate files with `php artisan make:*` and `--no-interaction`.
- Validation lives in Form Requests, not inline in controllers.
- Controllers return `Inertia::render(...)` for screens and `to_route(...)` for redirects, with
  `Inertia::flash('toast', ['type' => ..., 'message' => __(...)])` for user feedback.
- Prefer named routes and `route()` over hardcoded URLs.
- Run `vendor/bin/pint --dirty` before finishing.

### Testing

- **Every change is programmatically tested.** Pest feature tests by default; unit tests only
  where there is no HTTP surface.
- Use model factories, with named states for meaningful variants (`->admin()`, `->archived()`,
  `->inactive()`).
- Assert Inertia props with `assertInertia(fn ($page) => $page->component(...)->has(...)->where(...))`.
- Test the authorisation boundary on every protected route: guest, member, administrator, and
  self-targeting where a self-target is forbidden.
- Never delete or weaken an existing test to make a change pass.

### Definition of done for any task

- Feature works, and the acceptance criteria are all met.
- Tests cover it, including the negative and authorisation cases.
- `composer ci:check` passes and `npm run build` succeeds.
- Work is committed on the task branch with a descriptive message.

---

## The application

An invite-only web application that owns **game metadata and the seat roster** for a play-by-mail
strategy game. A separate game engine owns actual game state — turns, orders, map, results. Do
not model any of that here. This application knows who plays what, in which role, and nothing
about what happens inside a game.

Two independent role systems, deliberately unrelated. Getting this wrong is the single most
likely design error in the whole build:

- **`UserRole`** (`admin` | `member`) — an *application* role. `admin` grants access to `/admin`.
- **`GameRole`** (`player` | `gamemaster`) — a *game* role. It carries **zero** application
  permissions. A gamemaster seat does not grant admin access to anything.

---

## Task 0 — Scaffold and tooling

**Goal:** a running Laravel + Inertia Svelte skeleton with the full verification gate wired up,
so every later task has something real to pass.

Build:

- Fresh Laravel app with Inertia (Svelte adapter), Vite, Tailwind 4, and Wayfinder
  (`formVariants: true`).
- Pest 5, Pint, Larastan (at the strictest level that passes clean on a fresh app),
  ESLint, Prettier, `svelte-check`.
- SQLite for local and test databases. Session driver `database` in production config, `array` in
  tests. Add the sessions table migration — a later task reads it directly.
- npm scripts: `build`, `dev`, `lint`, `lint:check`, `format`, `format:check`, `types:check`.
- Composer scripts: `dev` (concurrent server + queue + vite), `test`, `lint`, `lint:check`,
  `types:check`, and **`ci:check`** running lint:check, format:check, types:check, and the test
  suite in that order.
- `.ai/rules/` with `index.md` mapping globs to rule files, and starter files for `app/**`,
  `resources/js/**`, `database/seeders/**`, and `**`.
- `.env.example` with the app name set to `"Epimethean Challenge"`.

**Acceptance criteria**

- `composer ci:check` passes on the bare skeleton.
- `npm run build` produces a Vite manifest.
- `php artisan migrate:fresh` runs clean.
- One smoke test asserts the root route renders an Inertia page.

---

## Task 1 — Authentication with Fortify (no registration)

**Goal:** the full authentication surface, minus the ability to sign up.

Build with Laravel Fortify, headless, with Svelte pages for every screen:

- Login, logout, forgot password, reset password, password confirmation.
- Email verification (`MustVerifyEmail` on the user model).
- Two-factor authentication (TOTP), with `confirm => true` and `confirmPassword => true`:
  QR code enrolment, confirmation step, recovery codes, and the two-factor challenge at login.
- Passkeys (WebAuthn) with `confirmPassword => true`: register, list, rename/delete, and passkey
  sign-in. Expose `/.well-known/passkey-endpoints` returning the enroll and manage URLs.
- Validation rule traits shared by everything that touches credentials: `PasswordValidationRules`
  (`passwordRules()`, `currentPasswordRules()`) and `ProfileValidationRules` (`profileRules()`,
  `nameRules()`, `emailRules(?int $userId)`), so the rules live in exactly one place.

**Critical:** `Features::registration()` must be **absent** from `config/fortify.php`. `/register`
must not exist. Accounts are created only by accepting an invitation (Task 5). Record this in
`.ai/rules` so a later agent does not "fix" the missing route.

**Acceptance criteria**

- A user can sign in, sign out, reset a password, verify an email, enrol and use TOTP, and
  register and use a passkey.
- `GET /register` returns 404.
- Tests cover each flow including failure paths: wrong password, expired reset link, invalid TOTP
  code, unverified user blocked from a verified-only route.

---

## Task 2 — Application shell

**Goal:** the chrome every signed-in screen sits inside.

Build:

- A sidebar layout: collapsible sidebar, header, breadcrumbs, user menu. Persist the sidebar's
  open state in a `sidebar_state` cookie and share it as an Inertia prop so the first paint is
  correct rather than flashing.
- An auth layout for the signed-out screens.
- Appearance switching — light / dark / system — persisted and applied before first paint, with no
  flash of the wrong theme.
- Toast notifications driven by the `toast` flash prop (`{ type, message }`), rendered globally.
- A shared Inertia middleware exposing: app name, `auth.user`, and `sidebarOpen`.
- A branded public landing page at `/` and a placeholder `/docs` page.

**Acceptance criteria**

- Signed-in pages render inside the shell with working breadcrumbs.
- Theme choice survives a reload with no flash.
- A controller flashing a toast results in a visible toast.
- Tests assert the shared props are present and that `/` and `/docs` render.

---

## Task 3 — Account settings

**Goal:** `/settings/*` for the signed-in user.

Build:

- `/settings` redirects to `/settings/profile`.
- **Profile** — update name and email. Changing the email address resets verification.
- **Delete account** — requires the account's **current password**. Destructive confirmation
  dialog.
- **Password** — update, requiring the current password, throttled (`throttle:6,1`).
- **Security** (`/settings/security`) — behind Laravel's `RequirePassword` middleware. Hosts the
  two-factor and passkey management from Task 1.
- **Appearance** — the theme picker from Task 2.

**Acceptance criteria**

- Every settings route requires `auth`; the destructive and security ones also require `verified`.
- Deleting an account without the correct current password fails.
- `/settings/security` redirects to password confirmation when the password was not recently
  confirmed.
- Tests cover each route's happy path and its authorisation failure.

---

## Task 4 — User roles and the administrator boundary

**Goal:** an `admin` role, an admin area that only admins can reach, and a safe way to mint the
first administrator.

Build:

- `UserRole` enum (`Admin` = `admin`, `Member` = `member`) with `label()` returning
  "Administrator" / "Member".
- A `role` column on `users`, indexed, defaulting to `member`, cast to the enum, with the same
  default in the model's `$attributes`.
- **`role` must NOT be mass-assignable.** Keep it out of the fillable list so no registration,
  invitation acceptance, or profile update can escalate an account by posting a `role` field.
  Assign it explicitly (`$user->role = UserRole::Admin`) at the few places that are allowed to.
- `User::isAdmin(): bool`.
- `EnsureUserIsAdmin` middleware, registered under the `admin` alias, used as
  `['auth', 'verified', 'admin']` on an `/admin` route group with an `admin.` name prefix.
- **`php artisan app:create-admin`** — the only supported way to mint the first administrator,
  including in production. Idempotent: promotes an existing account rather than failing, prompts
  for confirmation before promoting, and always prompts for the password interactively so it never
  lands in shell history. Validates through the Task 1 rule traits. Marks a newly created
  administrator's email as verified.
- A migration backfilling `email_verified_at` for any pre-existing accounts.

**Acceptance criteria**

- A member hitting any `/admin` route gets **403**; a guest is redirected to login.
- Posting `role=admin` to the profile update endpoint does not change the role.
- `app:create-admin` creates a new administrator, and run again against the same email reports it
  is already an administrator without erroring.
- Tests cover the middleware boundary and every command path.

---

## Task 5 — Invitations (invite-only registration)

**Goal:** the only path to a new account.

Build:

- `invitations` table: unique `email`, unique `token` (64 chars), `role` defaulting to `member`,
  nullable `invited_by_id` (FK to users, `nullOnDelete`), indexed `expires_at`, nullable
  `accepted_at`, timestamps.
- `Invitation` model: `EXPIRES_AFTER_DAYS = 7`, `generateToken()`, `hashToken()`, `isAccepted()`,
  `isExpired()`, `isPending()`, a `pending` scope, `invitedBy` relation. `token` is hidden from
  serialisation.
- **Tokens are stored as a sha256 hash. Only the emailed link ever carries the plain text.** A
  leaked database must not yield usable invitation links, and a token therefore cannot be
  recovered — resending issues a *new* token and invalidates the old link. Record this.
- `IssueInvitation` action — the single place that mints tokens and sends mail. Both the create
  and the resend endpoint go through it. It upserts on email, so re-inviting an address reuses the
  row and clears `accepted_at`.
- `InvitationNotification` — the mailed invitation carrying the plain-text link.
- Admin screen at `/admin/invitations`: list every invitation with email, role, status
  (pending / accepted / expired), who invited them, and expiry; create an invitation choosing a
  role; resend; revoke. Resending an already-accepted invitation is **403**.
- Public acceptance flow, guest-only: `GET /invitations/{token}` shows the acceptance form with
  the invited email pre-filled and read-only; `POST /invitations/{token}` (throttled `6,1`)
  creates the account. An invalid, expired, or already-accepted token renders a dedicated page
  distinguishing the three reasons.
- Acceptance delegates account creation to the **same action Fortify's registration would use**,
  so password and profile rules stay in one place. It assigns the invitation's role explicitly,
  stamps `accepted_at`, fires `Registered`, signs the user in, and regenerates the session.
- **Accepting an invitation does not verify the email address.** Clicking a mailed link is not
  proof of control, so the new account still completes the standard verification flow.
- Configure a real mail transport (the reference uses Mailgun via `symfony/mailgun-mailer`).

**Acceptance criteria**

- An accepted invitation creates an account with the invited role and an **unverified** email.
- A used, expired, or unknown token cannot create an account, and each renders its own reason.
- Resending changes the stored token hash, so the previous link stops working.
- The plain-text token appears in the mail and nowhere in the database.
- A member cannot reach any invitation admin route.

---

## Task 6 — Administration of users and sessions

**Goal:** admin visibility and control over accounts and signed-in browsers.

Build:

- **`/admin/users`** — list every account: name, email, role, whether the email is verified,
  whether two-factor is enabled, active session count, created date. Change an account's role.
  Delete an account. Both actions **403 when the administrator targets themselves** — that is what
  stops the last administrator locking everyone out.
- The `sessions.user_id` foreign key is deliberately unconstrained, so deleting a user does not
  cascade to their sessions. Delete them explicitly in the destroy path. Passkeys **do** cascade.
- **`/admin/sessions`** — list every authenticated session: the account, IP, browser and platform
  parsed from the user agent, last activity, and whether it is the current session. Sign out a
  single session; sign out all other sessions.
- A `Session` model mapping the `sessions` table: string non-incrementing key, no timestamps,
  helpers for `lastActiveAt()`, `browser()`, `platform()`.
- **The session row's primary key IS the live session identifier — anything holding it can
  impersonate that browser.** So it must never reach the frontend and must never be a route
  parameter. Address sessions by `digest()` (sha256 of the id) and resolve with `findByDigest()`,
  comparing in PHP with `hash_equals` because SQLite has no `sha2()`. Signing out your own current
  session is **403**. Record this rule — it is the kind of thing a later refactor undoes.

**Acceptance criteria**

- No raw session id appears in any Inertia prop or URL — assert this in a test.
- An administrator cannot change their own role, delete their own account, or sign out their own
  session; each returns 403.
- Deleting a user removes their session rows.
- A member cannot reach any of these routes.

---

## Task 7 — Game and seat management for administrators

**Goal:** the game roster.

Build:

- `GameStatus` enum: `Setup`, `Active`, `Paused`, `Completed`, `Archived`, with labels.
- `GameRole` enum: `Player`, `Gamemaster`, with labels. Document in the enum itself that it
  carries no application permissions.
- `games` table: unique `name`, unique `short_name` (max 16), indexed `status` defaulting to
  `setup`, timestamps. `Game` model with a `seats()` relation, an `activeSeats()` relation, and an
  `unarchived` scope.
- `game_seats` table: `game_id` and `user_id` (both cascade on delete), `role` defaulting to
  `player`, `is_active` defaulting to true, timestamps, and a **unique index on
  `(game_id, user_id)`**.
- `/admin/games` — list every game with name, short name, status, seat counts (active of total),
  created date. Create a game (it starts in `setup` with no seats). Delete a game, behind a
  confirmation naming how many seats go with it.
- `/admin/games/{game}` — the game's metadata and its seat roster. Edit name, short name and
  status. Add a seat by choosing an account and a game role. Change a seat's role. Retire and
  reactivate a seat.
- Seat routes nested under the game inside `Route::scopeBindings()`, so a seat belonging to
  another game 404s instead of being edited through the wrong game's URL.

Non-obvious rules to implement and record:

- **Seats are retired, never deleted.** There is no seat destroy endpoint — retire with
  `is_active = false`, because engine history keeps referring to the seat.
- Consequently the uniqueness check **counts retired seats too**. Bringing a departed account back
  is a *reactivation*, never a second row — so the "assignable accounts" list on the game screen
  excludes every account that already holds a seat, active or retired. A duplicate attempt is
  rejected with a pointed message: "That account already has a seat in this game."
- **Short names are uppercased during validation** and limited to 16 characters of `[A-Z0-9-]`;
  they appear in turn reports and file names. Rejection message: "The short name may only contain
  letters, numbers and hyphens."
- Use the dedicated `activeSeats()` relation for `withCount(['seats', 'activeSeats'])` so
  Larastan can resolve `active_seats_count`; do not use a closure alias.

**Acceptance criteria**

- Creating a game with `short_name` `run-1` stores `RUN-1`; `run 1` is rejected.
- Adding a second seat for an account that already has one — active *or* retired — is rejected
  with that message.
- A seat id from game A returns 404 on game B's seat routes.
- Seat counts on the index distinguish active from total.
- A member cannot reach any game admin route.

---

## Task 8 — Development member accounts

**Goal:** six known accounts so the human can log in and click around.

Build a `DevelopmentUserSeeder` seeding six verified members, `user1@…` through `user6@…`, each
with a matching known password, exposed through static `email(int)` and `password(int)` helpers so
tests reference the helpers rather than hardcoding credentials. Call it from `DatabaseSeeder`, so
plain `php artisan db:seed` includes them.

**Because those passwords are public, the seeder must return early unless
`app()->environment(['local', 'testing'])`.** It must also skip accounts that already exist, so it
is safe to re-run after one of them has been renamed or promoted. Record both rules for seeders.

**Acceptance criteria**

- Seeding in `local` creates six verified members; seeding in a production environment creates
  none.
- Re-running the seeder is a no-op and does not overwrite a promoted account.
- A test signs in as one of them using the helpers.

---

## Task 9 — Impersonation

**Goal:** an administrator can view the application as a member, and can always get back.

Build:

- An `Impersonation` action owning the `impersonator_id` session key. The controller and the
  shared Inertia middleware both go through it — nothing else touches the session key directly.
  Methods: `isActive()`, `impersonator()`, `start()`, `stop()`, `abandon()`.
- `POST /admin/users/{user}/impersonate` — starts an impersonation and redirects to the dashboard.
  **Refuses administrators and self (403).** An impersonated session is therefore always a member,
  which is what makes the admin middleware alone block a second, chained impersonation.
- `DELETE /impersonate` — ends it and returns the administrator to the users screen.
  **This route must live outside the admin group and outside `verified`**, or an impersonated or
  unverified session has no way back. Comment the route to that effect.
- A persistent banner, fed by a shared `impersonation` prop, offering the way out.
- `Auth::login()` migrates the session to a fresh id while keeping its data, so no extra
  `regenerate()` is needed.
- If the administrator was deleted or demoted mid-impersonation, stopping **signs the session out
  entirely** rather than handing it to a non-admin. The banner still renders in that case — with
  "an administrator" instead of a name — so the session is never stranded without an exit.
- Sensitive account actions need no extra guarding: profile deletion and password changes already
  require the account's current password, which the impersonating administrator does not have.

**Acceptance criteria**

- An administrator can impersonate a member and return.
- Impersonating another administrator, or yourself, is 403.
- An impersonated session cannot reach `/admin` and cannot start a nested impersonation.
- With the impersonator deleted mid-session, stopping logs out rather than escalating.
- The banner renders for a deleted impersonator.

---

## Task 10 — The member games dashboard

**Goal:** what a non-administrator sees when they sign in.

Replace the placeholder dashboard with the games the signed-in account holds a seat in.

- Two sections: games where they hold the **gamemaster** role, and games where they hold the
  **player** role.
- **An empty section is not rendered at all.**
- An account with no seats in any game sees an explanatory blurb instead.
- Only **active** seats count. A retired seat means they are out of that game.
- Default ordering within each section is by **short name**.
- **Archived games are hidden by default**, with a toggle to show them, per section. Ship the
  archived games in the payload flagged so the toggle is instant with no round trip, and render
  the toggle only when that section actually has archived games to reveal. If a section's games are
  *all* archived, say so rather than rendering an empty list.
- Add the `gameSeats` relation to the user model. Name it unambiguously — a bare `seats` on a user
  reads poorly.

**Acceptance criteria**

- Sections split correctly by game role, each ordered by short name.
- Retired seats and other accounts' games never appear.
- An account with no seats gets the blurb and no section headings.
- The archived toggle reveals and hides without a server request.
- Tests assert the prop shape, the ordering, the archived flag, and the exclusions.

---

## When every task is merged

Leave `main` in a state the human can pick up and drive in a browser. Verify yourself, then report:

1. `composer ci:check` passes and `npm run build` succeeds on `main`.
2. `php artisan migrate:fresh --seed` from empty works.
3. How to start it (`composer run dev`) and the local URL.
4. The six seeded member accounts and their passwords.
5. The exact `php artisan app:create-admin` invocation to mint an administrator, and how to send
   themselves an invitation from `/admin/invitations` to exercise the acceptance flow.
6. A short list of anything you deliberately did differently from the React reference because
   Svelte made a different approach the better one — this is the most useful output of the whole
   exercise for the comparison, so be specific about *why*, not just *what*.
