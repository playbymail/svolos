# Sessions and the accounts administration screen

Globs: `app/Models/Session.php`, `app/Http/Controllers/Admin/SessionController.php`,
`app/Http/Controllers/Admin/UserController.php`, `app/Http/Requests/Admin/SessionDestroyRequest.php`,
`app/Http/Requests/Admin/UserRoleUpdateRequest.php`, `database/factories/SessionFactory.php`,
`resources/js/pages/admin/sessions/**`, `resources/js/pages/admin/users/**`,
`resources/js/components/UserRoleForm.svelte`, `tests/Feature/Admin/**`,
`tests/Unit/SessionModelTest.php`

## A `sessions.id` **is** the live session credential — address sessions by `digest()`, never by id

This is the rule most likely to be undone by a later refactor, because every instinct says "the
primary key goes in the URL" and every other model in this application does exactly that. `sessions`
is different: its primary key is the value the browser holds in its session cookie, so **anything
that learns it can impersonate that browser** until the session expires. It is a credential that
happens to be stored in a primary key column.

So:

- it must never reach the frontend — not in an Inertia prop, not in a data attribute, not in rendered
  HTML. `Session` declares `#[Hidden(['id', 'payload'])]` as a backstop for somebody passing the
  model straight to `Inertia::render()`, but the rule is that presenters build the array by hand and
  simply never include it;
- it must never be a route parameter. `admin.sessions.destroy` is `DELETE /admin/sessions` with the
  digest in the **request body**, not `DELETE /admin/sessions/{session}`. A URL is written to browser
  history, proxy logs and referrer headers, so a URL carrying this value hands it to three places
  that keep it longer than the session does. `tests/Feature/Admin/SessionIdentifierTest.php` asserts
  every `admin.sessions.*` route has an empty `parameterNames()`, so adding `{session}` fails;
- sessions are addressed by `Session::digest()` (sha256 of the id) and resolved with
  `Session::findByDigest()`.

`findByDigest()` loads candidates and compares in PHP with `hash_equals`. That is not a missing
query optimisation: **SQLite has no `sha2()`**, so the digest cannot be computed in SQL, and a digest
by design carries nothing that could narrow the search. A malformed digest is rejected by a regex
before the scan, and `SessionDestroyRequest` pins the shape to 64 lowercase hex characters, so a
stray value never costs one.

`SessionIdentifierTest` is written to fail rather than to pass: it walks the whole page object
recursively — values *and* array keys — searches the rendered HTML as well as the decoded props,
looks for **every** identifier in the database rather than the interesting one, pins the presented
key set as a whitelist rather than merely asserting `id` is absent, and asserts a **positive
control** on each screen so a check that stopped looking in the right place cannot keep passing.
Response *headers* are deliberately not searched — the encrypted session cookie is how a session
works, and is the one place the identifier belongs.

## Signing out your own current session through the admin screen is a 403

Not a logout, and not a no-op. The screen exists to remove *other people's* access; an administrator
who wants to leave uses the ordinary log-out. `destroyOthers()` covers the real bulk case — end every
session in the installation except the one reading the result — so there is no need for this screen
to be able to end its own.

`destroyOthers()` includes guest rows (a global sign-out that left rows behind would not be one)
while `index()` excludes them via the `authenticated()` scope ("somebody loaded the login page" is
not a signed-in browser). The current session is excluded in PHP rather than with a `whereNot`, so
there stays exactly one rule about how two sessions are compared: `hash_equals` on the identifier.

## Both account writes 403 when an administrator targets themselves

`UserController::updateRole()` and `UserController::destroy()` both call
`abortWhenTargetingSelf()`. This is not politeness about self-service — it is the only thing standing
between an installation and having **no administrator at all**, and recovering from that needs a
shell on the server (`app:create-admin`, see [roles.md](roles.md)). It must be unreachable through
the browser, not merely discouraged.

The guard reads the user through `Controller::authenticatedUser()` rather than `$request->user()`
on purpose: `$user->is(null)` is `false`, so comparing against a nullable would make the guard fail
**open** on exactly the request it exists for. See the idiom in [php.md](php.md).

`is_self` in the props is presentation only — it lets the screen leave out controls that would 403.
Do not turn a hidden button into the check.

## Deleting an account deletes its sessions explicitly; passkeys cascade on their own

The asymmetry is deliberate and both halves are pinned by tests:

- `sessions.user_id` carries **no foreign key** (`0001_01_01_000000_create_users_table.php` writes
  `foreignId('user_id')->nullable()->index()`, with no `constrained()`), so nothing cascades. A row
  left behind is a browser still holding a live session cookie for an account that no longer exists.
  `destroy()` therefore runs `$user->sessions()->delete()` inside the same transaction as
  `$user->delete()`. A test asserts the schema really has no foreign key, so if one is ever added the
  explicit delete becomes provably redundant rather than silently duplicated.
- `passkeys.user_id` **does** have `cascadeOnDelete`, so the controller must **not** delete passkeys.
  A test asserts the constraint's `on_delete` is `cascade` and another asserts the rows actually
  disappear, specifically so nobody "fixes" a working cascade by duplicating it in PHP.
- `invitations.invited_by_id` is `nullOnDelete`, so invitations an administrator issued survive them
  with nobody attached, which is right for a link that has already been emailed
  ([invitations.md](invitations.md)).

## `role` is assigned explicitly here, and stays out of `#[Fillable]`

`/admin/users` is the one screen in the application that legitimately changes `users.role`, and it
does so with `$user->role = …`. Do **not** add `role` to `User`'s `#[Fillable]` list to make this
tidier — everything else that writes an account writes it from request input, so a fillable `role`
would let any of those posts promote themselves. See [roles.md](roles.md).

`UserRoleUpdateRequest` accepts nothing but `role`; a test posts `name`, `email` and `password`
alongside it and asserts none of them changed.

## `SESSION_DRIVER=array` in tests: pin the identifier with `TestCase::pinSessionId()`

`phpunit.xml` sets `SESSION_DRIVER=array`, and the test harness sends no session cookie back between
requests, so `StartSession` mints a **fresh** identifier on every single request and there is never a
`sessions` row corresponding to the request being made. That would leave the `is_current` flag and
both own-session 403s untestable, and a guard nothing exercises is a guard that has already stopped
working.

`Tests\TestCase::pinSessionId()` seeds the (encrypted) session cookie with a value
`Session::isValidId()` accepts — 40 random alphanumeric characters — which makes `StartSession` adopt
it instead of generating one. A test then creates a real row with that id and the request genuinely
*is* that session. This only adds a cookie when called, so it changes nothing for any test that does
not call it.

## `Session` maps the framework's table: string key, no timestamps, parsing in the model

String non-incrementing primary key, `$timestamps = false` (the framework's handler keeps only
`last_activity`, through the query builder). `last_activity` stays a plain `int` — that is what the
handler writes and reads — and `lastActiveAt()` is the only place that turns it into a
`CarbonImmutable`. Do not cast the column to `datetime`; the helper would become a redundant getter
and the model would start disagreeing with the handler about the column's type.

`browser()` and `platform()` are **hand-rolled**, matching ordered token tables in the model. Nothing
in the dependency tree parses a user agent (`laravel/agent-detector` detects *AI agents*, not
browsers; there is no `jenssegers/agent`), and a whole dependency for two labels in one admin table
is not worth it. **The order of those tables is the entire algorithm** and is not alphabetical:
every Chromium browser also claims `Chrome` and Chrome also claims `Safari`, so `Edg` must precede
`Chrome` and `Chrome` must precede `Safari`; `Android` must precede `Linux` because an Android agent
says `Linux; Android`; and the iOS device tokens must precede `Mac OS X` because iOS says
`like Mac OS X`. `tests/Unit/SessionModelTest.php` covers each branch with a real user agent — if you
reorder the tables, that file is what tells you.

## Frontend

`resources/js/components/UserRoleForm.svelte` is a per-row component (like `PasskeyItem.svelte`) so
each picker can hold the in-progress choice in a **writable `$derived`** off that row's `user.role`.
It snaps back to the server's value whenever the prop changes, which is what stops a refused change
leaving a picker showing a role the account does not hold. A map of choices on the page would have to
be `$state` re-seeded from an `$effect` — a second copy of the truth, and `eslint-plugin-svelte`'s
`prefer-writable-derived` rejects it.

The sessions table is keyed by `digest`, because that is the only identifier the server sends. Do not
add an `id` to the props to key it with.
