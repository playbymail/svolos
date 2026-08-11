# Authentication rules

Globs: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/**`,
`app/Concerns/*ValidationRules.php`, `resources/js/pages/auth/**`, `tests/Feature/Auth/**`

Authentication is Laravel Fortify, headless, with Svelte pages for every screen. Enabled features:
`resetPasswords`, `emailVerification`, `twoFactorAuthentication(['confirm' => true, 'confirmPassword'
=> true])`, `passkeys(['confirmPassword' => true])`.

## Registration is deliberately absent — `/register` must 404

Accounts are created **only** by accepting an invitation, so `Features::registration()` is not in
`config/fortify.php` and Fortify registers no `/register` routes. `Fortify::registerView()` and
`resources/js/pages/auth/Register.svelte` are gone, and neither `Login.svelte` nor `Welcome.svelte`
links to a sign-up page.

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

## Credential validation rules live in one place

`App\Concerns\PasswordValidationRules` (`passwordRules()`, `currentPasswordRules()`) and
`App\Concerns\ProfileValidationRules` (`profileRules(?int $userId)`, `nameRules()`,
`emailRules(?int $userId)`). Anything that touches credentials — Fortify actions, Form Requests,
invitation acceptance — uses these traits rather than re-declaring rules inline.

## `User` implements `MustVerifyEmail`

So the `verified` middleware genuinely blocks unverified users. The starter kit shipped with the
interface commented out, which made `verified` a no-op and silently let unverified users through
every "verified" route. If a test needs to bypass verification, use a verified factory user
(the default) — do not remove the interface.

## Passkeys: Fortify has no rename endpoint

Fortify/laravel-passkeys ship registration, listing and deletion only. Renaming is
`PUT user/passkeys/{passkey}` → `App\Http\Controllers\Auth\PasskeyController@update`, registered in
`routes/settings.php` with the same `auth` + `RequirePassword` middleware Fortify puts on its own
passkey management routes, plus the ownership check the package's `destroy` performs. If a future
package version adds a rename route, delete ours rather than keeping both.

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
