# Authentication rules

Globs: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/**`,
`app/Concerns/*ValidationRules.php`, `resources/js/pages/auth/**`, `tests/Feature/Auth/**`,
`routes/dev.php`, `app/Http/Controllers/Dev/**`, `tests/Feature/Dev/**`

Authentication is Laravel Fortify, headless, with Svelte pages for every screen. Enabled features:
`resetPasswords`, `emailVerification`, `twoFactorAuthentication(['confirm' => true, 'confirmPassword'
=> true])`, `passkeys(['confirmPassword' => true])`.

## Registration is deliberately absent — `/register` must 404

**Nobody creates their own account.** `Features::registration()` is not in `config/fortify.php`,
Fortify registers no `/register` routes, `Fortify::registerView()` and
`resources/js/pages/auth/Register.svelte` are gone, and neither `Login.svelte` nor `Welcome.svelte`
links to a sign-up page.

The rule is about *self-service*, not about there being one door. Three things create an account, and
every one of them is somebody else deciding you should have it:

- a person accepts an **invitation** an administrator sent ([invitations.md](invitations.md));
- an operator with a shell runs **`app:create-admin`** ([roles.md](roles.md));
- an administrator creates an **agent** on `/admin/agents` ([agents.md](agents.md)), which is an
  account no person ever signs in to.

Adding a fourth is a decision, not a convenience. What must not come back is a route a *stranger* can
reach that ends in an account.

This is the design, not an oversight. Do not "fix" the missing route, do not re-add the feature
(not commented out, not behind a config flag), and do not restore a sign-up link.
`tests/Feature/Auth/RegistrationDisabledTest.php` guards it with positive assertions.

Because the `register` route name no longer exists, `route('register')` **throws** rather than
returning a URL. Assert against the literal path `/register`. The same applies on the frontend: there
is no `register` export in the generated `@/routes`, so importing one breaks `npm run build`.

## `app/Actions/Fortify/CreateNewUser.php` is retained on purpose

It looks like dead code with registration disabled. It is not: invitation acceptance creates accounts
through this action so the password and profile rules stay in exactly one place, and
`FortifyServiceProvider::configureActions()` still binds it via `Fortify::createUsersUsing()`. Do not
delete it.

It sets no `role` and does not verify the email address, and both omissions are correct.
`InvitationAcceptanceController` assigns the role explicitly afterwards, and an invited account is
supposed to arrive **unverified** — see [invitations.md](invitations.md).

## Credential validation rules live in one place

`App\Concerns\PasswordValidationRules` (`passwordRules()`, `currentPasswordRules()`) and
`App\Concerns\ProfileValidationRules` (`profileRules(?int $userId)`, `nameRules()`,
`emailRules(?int $userId)`). Anything that touches credentials — Fortify actions, Form Requests,
invitation acceptance — uses these traits rather than re-declaring rules inline.

## `User` implements `MustVerifyEmail`

So the `verified` middleware genuinely blocks unverified users — including a brand new account that
has just accepted an invitation, which is the intended path and not an accident
([invitations.md](invitations.md)). The starter kit shipped with the
interface commented out, which made `verified` a no-op and silently let unverified users through
every "verified" route. If a test needs to bypass verification, use a verified factory user
(the default) — do not remove the interface.

## Passkeys: Fortify has no rename endpoint

Fortify/laravel-passkeys ship registration, listing and deletion only. Renaming is
`PUT user/passkeys/{passkey}` → `App\Http\Controllers\Auth\PasskeyController@update`, registered in
`routes/settings.php` with the same `auth` + `RequirePassword` middleware Fortify puts on its own
passkey management routes, plus the ownership check the package's `destroy` performs. If a future
package version adds a rename route, delete ours rather than keeping both.

## `/__dev/log-me-in/{email}` is a password-free sign-in, and it is **local only**

`GET /__dev/log-me-in/{email}?returnTo=/some/path` puts the session on that account and lands on that
path. It exists so the application can be driven in a real browser without a password — by hand, and
by an agent that is not permitted to type credentials into a login form.

It is an authentication bypass, so it has **two independent gates and both must stay**:

1. `routes/dev.php` is only required at all when `app()->environment('local')`, from the bottom of
   `routes/web.php`. Outside local the route does not exist and the URL is an ordinary 404.
2. `Dev\AgentLoginController` checks the environment **again** on every request. This is not the same
   check twice: `php artisan route:cache` on a workstation bakes the route into a file, and a deploy
   that shipped that file would otherwise carry a working bypass into production.

Neither gate is a config flag, deliberately — a flag is something somebody can switch on in the wrong
place, while `APP_ENV=local` already means "this installation is a workstation". Do not add one, and
do not "simplify" by dropping the controller's own check.

Three smaller rules it keeps:

- **It signs accounts in; it never creates one.** Accounts come from invitations, and this does not
  become a second door into that.
- **`returnTo` must be a path on this application.** `//host` and `/\host` are protocol-relative URLs
  wearing a leading slash, and both fall back to the dashboard. An open redirect is not worth having
  anywhere, least of all in a URL that ends up in shell history and agent transcripts.
- **It clears the impersonation marker**, because a fresh sign-in as somebody else must not inherit a
  banner offering to return to an administrator this session never was.

`tests/Feature/Dev/AgentLoginTest.php` tests both gates separately — the route's absence in the
suite's own environment, and the controller refusing when the route is registered by hand anyway,
which is the shipped-route-cache case.

## Testing auth

- `Tests\TestCase::skipUnlessFortifyHas()` skips a test when a feature is off. Never use it to guard
  behaviour that must be *absent* — a skipped test asserts nothing. Assert absence positively.
- `UserFactory::withTwoFactor()` generates a **real** base32 TOTP secret and eight real recovery
  codes, so tests can derive the code an authenticator app would currently show via
  `app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret)` and drive the challenge for
  real. `withUnconfirmedTwoFactor()` is the mid-enrolment variant.
- `Database\Factories\PasskeyFactory` covers `Laravel\Passkeys\Passkey`, which does not use
  `HasFactory` — instantiate it directly: `PasskeyFactory::new()->for($user)->create()`.
- WebAuthn signature verification cannot be forged from a feature test. `PasskeyTest` covers the
  endpoints, ownership boundaries and failure paths for real, and swaps
  `Laravel\Passkeys\Actions\VerifyPasskey` for the one sign-in success case so the assertion is about
  our wiring (guard login, redirect) rather than the package's cryptography.
- Two-factor confirmation errors arrive in the `confirmTwoFactorAuthentication` error bag:
  `assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication')`.
- An expired reset link produces the same `email` error as a forged token, because the broker treats
  a token older than `auth.passwords.users.expire` as missing. Reach it with
  `$this->travel(...)->minutes()`.
