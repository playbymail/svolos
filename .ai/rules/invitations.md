# Invitations — the only way an account is created

Globs: `app/Models/Invitation.php`, `app/Actions/Invitations/**`, `app/Notifications/**`,
`app/Enums/Invitation*.php`, `app/Http/Controllers/InvitationAcceptanceController.php`,
`app/Http/Controllers/Admin/InvitationController.php`, `app/Http/Requests/InvitationAcceptanceRequest.php`,
`app/Http/Requests/Admin/InvitationStoreRequest.php`, `database/migrations/*_create_invitations_table.php`,
`resources/js/pages/invitations/**`, `resources/js/pages/admin/invitations/**`,
`tests/Feature/Invitations/**`, `config/mail.php`, `config/services.php`

Registration is deliberately absent (see [auth.md](auth.md)); accepting an invitation is the whole
account-creation path.

## `invitations.token` stores a sha256 hash — only the email carries the plain text

A dump of the database must not yield a usable invitation link, for the same reason it must not yield
a usable password. `Invitation::generateToken()` mints 64 random characters,
`Invitation::hashToken()` is `hash('sha256', …)`, and the column stores only the digest (also 64
characters, which is why the column is `string('token', 64)` — a longer value means somebody stored
the wrong thing). `token` is in `#[Hidden]` so it cannot reach the frontend either.

It is a plain unsalted sha256 rather than `Hash::make()` on purpose: the column has to be
*searchable*, because an acceptance request arrives carrying the token and nothing else, and a
per-row salt would force a scan of every invitation. Password hashing's slowness buys nothing here —
the input is 64 random characters from our own generator, not a human-chosen secret.

**The consequence, which is behaviour and not a defect: a token cannot be recovered, so resending
issues a *new* token and the previously emailed link stops working.** Do not "fix" this by storing
the plain text alongside the hash, and do not add a `plain_token` column for the resend path. A
useful side effect is that a link forwarded to the wrong person can be killed by resending.

Because the old hash is overwritten, a superseded link reports `unknown` rather than `expired` —
its hash is no longer in the table, so it is indistinguishable from a token that never existed. That
is correct, and `tests/Feature/Invitations/InvitationTokenTest.php` pins it, along with the
plain-text token appearing in the real outgoing email and in no column of any table.

## `IssueInvitation` is the only place that mints a token or sends the mail

Both the create and the resend endpoint call it, so the properties above live in one readable place.
It **upserts on `email`**: re-inviting an address reuses the row (so there is never a second live link
for one mailbox) and clears `accepted_at`, because a new link is a new offer and a row still marked
accepted would render as "already used".

`Invitation` declares **no `#[Fillable]`** and the action assigns every attribute one at a time. That
is deliberate: `token` and `invited_by_id` must never be able to arrive from request input, and with
nothing fillable a future `Invitation::create($request->validated())` throws instead of quietly
trusting it. (This is a different boundary from `users.role` in [roles.md](roles.md) — same reasoning,
different table.)

`invited_by_id` follows the **live** link, so resending sets it to the administrator who resent it.
The column answers "whose invitation is currently outstanding", not "who first had the idea".

`InvitationNotification` takes the plain token as a constructor argument because the invitation does
not have it. If it ever starts reading `$invitation->token`, the link it builds will be the hash and
nothing will be able to accept it. It is not queued: invitations are sent one at a time by a human who
is watching, and the default queue connection is `database`, so a queued invitation on a host with no
worker is an invitation that silently never arrives.

## Accepting an invitation does **not** verify the email address

This looks like a bug until you know why. Clicking a link in an email proves somebody read the
mailbox — a forwarded invitation, a shared inbox, an assistant — not that the person filling in the
form controls it. So acceptance fires `Registered` (which is what makes the framework send the
verification notification), and the new account completes the ordinary verification flow before
`verified` lets it anywhere. Do not call `markEmailAsVerified()` here, and do not write
`email_verified_at`.

`app:create-admin` is the deliberate contrast: a shell on the server is a stronger claim than a
clicked link, and there is no mailbox to send to yet ([roles.md](roles.md)).

Note for the level-8 gate: `$user->email_verified_at = now()` is a PHPStan error anyway, because
`Date::use(CarbonImmutable::class)` in `AppServiceProvider` makes Larastan infer `CarbonImmutable`
while `User`'s `@property` says `Illuminate\Support\Carbon`. `Invitation` sidesteps that by declaring
its own timestamps as `CarbonImmutable` in the class docblock, which is what lets
`$invitation->expires_at = now()->addDays(…)` and `$invitation->accepted_at = now()` type-check.

## What acceptance does, and where each part must stay

`InvitationAcceptanceController::store()`:

- creates the account through `App\Actions\Fortify\CreateNewUser` — the same action Fortify's
  registration would have used — so the password and profile rules stay in exactly one place;
- takes the **email address from the invitation**, never from the request. The form field is
  read-only as a courtesy; the server ignores what is posted, so editing it in the browser cannot
  point an invitation at a different mailbox;
- assigns the role **explicitly** afterwards (`$user->role = $invitation->role`). `role` is not
  mass-assignable, which is what stops a posted `role=admin` riding in through `CreateNewUser`;
- stamps `accepted_at`, signs the user in, and regenerates the session;
- wraps the three writes (account, role, `accepted_at`) in one transaction so a half-applied
  acceptance cannot leave a live invitation pointing at an account that already exists.

Both routes are **guest-only** (`guest` middleware, which redirects to the dashboard). Acceptance
creates a *new* account and signs it in, so an authenticated visitor is on the wrong screen by
definition; letting them through would mean minting a second account, or consuming somebody else's
invitation from inside their own session. Somebody already signed in has to log out first. The POST is
additionally `throttle:6,1` — the token is the only credential the route has.

## Three failure reasons, three different answers

An unusable link renders `invitations/Invalid` with an `App\Enums\InvitationLinkProblem`:
`unknown`, `expired`, `accepted`. Never collapse them into one generic error — the remedies differ
(check the link, ask for a new invitation, just log in), and somebody who has already used their
invitation would otherwise never learn that their account exists. Accepted is reported ahead of
expired: an invitation that was used and has since passed its expiry was still used.

Telling an unauthenticated visitor which case applies leaks nothing worth having — the token is
unguessable, so knowing that one is expired rather than unknown gets nobody closer to a valid one.

## Administration screen

`/admin/invitations`, inside the `['auth', 'verified', 'admin']` group ([roles.md](roles.md)). It
lists every invitation, including accepted and expired ones: the list doubles as the record of who was
let in and who never arrived, and hiding an expired row is how the same person gets invited three
times. Status is derived (`Invitation::status()`), never stored — a column would go stale the moment an
invitation expired without anybody touching the row.

- **Resending an accepted invitation is a 403**, not a no-op: there is no link left to send, the
  account already exists, and quietly succeeding would suggest otherwise.
- Resending an **expired** invitation is the normal remedy and brings it back into the pending window.
- Revoking deletes the row, which is what kills the link (acceptance looks the hash up in this table).
  Revoking an **accepted** invitation is allowed and removes only the record — the account keeps
  working, so this is not the way to remove somebody's access.
- `InvitationStoreRequest` reuses `emailRules()`, which brings uniqueness against `users` with it: an
  address that already has an account cannot be invited, because acceptance would only fail on that
  same rule after the mail had gone out. There is deliberately **no** uniqueness rule against
  `invitations` — the upsert is the reissue path.

## Frontend

The public pages live in `resources/js/pages/invitations/` and are mapped to `AuthLayout` by an
explicit case in `resources/js/app.ts`: they are signed-out, single-purpose screens, which is what
AuthLayout is. They are kept **out** of `pages/auth/` because that directory is the Fortify surface
and registration is absent from it — sharing a layout is not joining that surface. See
[frontend.md](frontend.md) for the `layout`-export mechanics, including the function form
`Invalid.svelte` uses to pass server-chosen copy into the layout.

## Mail transport

`symfony/mailgun-mailer` provides the `mailgun` mailer in `config/mail.php`, with credentials in
`config/services.php` from `MAILGUN_DOMAIN` / `MAILGUN_SECRET` / `MAILGUN_ENDPOINT`.
`symfony/http-client` is required alongside it and is **not** optional: the bridge only dev-requires
it, but `AbstractHttpTransport` calls `HttpClient::create()` when no client is injected, so without it
building the transport throws a `LogicException` at send time rather than at boot.

`services.mailgun.scheme` is `https`, so Laravel builds the DSN `mailgun+https` and the transport is
`MailgunHttpTransport` (posting the assembled MIME message), not `MailgunApiTransport`. A test asserts
the class, so changing the scheme means changing that test on purpose.

**The default mailer stays `log`** (`env('MAIL_MAILER', 'log')`), and `.env.example` leaves the Mailgun
values empty. A fresh clone must run and its suite must pass with no account at a mail provider;
`phpunit.xml` overrides the suite to `array` so the tests read real outgoing messages. Never commit
credentials to `config/` or `.env.example`.

## Testing

- `tests/Pest.php` exposes two helpers: `invitationWithToken()` creates an invitation *and* returns the
  plain token (nothing can recover it from a row, so the test has to mint it), and
  `invitationTokenFromLastEmail()` reads the token out of the message the application really sent via
  the array transport. Prefer the latter over `Notification::fake()` when the assertion is "the token
  reaches the mailbox" — a fake still passes when the mail body never contained the link.
- `actingAs()` persists across requests within a test, so a test that acts as an administrator and then
  follows an invitation link must `Auth::logout()` first, or `guest` redirects and the assertion is
  about the wrong thing.
