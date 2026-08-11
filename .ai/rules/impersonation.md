# Impersonation

Globs: `app/Actions/Impersonation/**`, `app/Http/Controllers/ImpersonationController.php`,
`app/Http/Middleware/EnsureUserIsAdmin.php`, `app/Http/Middleware/HandleInertiaRequests.php`,
`resources/js/components/ImpersonationBanner.svelte`, `resources/js/lib/impersonation-banner.ts`,
`tests/Feature/Admin/ImpersonationTest.php`

## `impersonator_id` is a privilege record, and only `ImpersonationSession` writes it

An impersonated session is an ordinary authenticated session for the **target** account plus one
session key — `ImpersonationSession::SESSION_KEY` (`impersonator_id`) — naming the administrator
behind it. Everything else follows: `$request->user()` really is the target, their data and their
permissions apply, and the only trace of who is driving is that key.

The key is not a credential but it is worth as much as one: `stop()` reads it and signs that account
back in, so anything able to write it can hand itself an administrator's account. It is therefore
written in exactly one place, `App\Actions\Impersonation\ImpersonationSession`, only from a request
that has already cleared `EnsureUserIsAdmin`, and never from request input. Four callers read it —
the controller's two actions, `EnsureUserIsAdmin`, and `HandleInertiaRequests` — and the key name
appears in none of them, which is the point of the class.

## `impersonation.stop` is outside `/admin` on purpose — never move it in

Start and stop sit on opposite sides of the administrator boundary, which is why one controller is
registered by two routes rather than living in `Admin\`:

- `admin.users.impersonate` (`POST /admin/users/{user}/impersonate`) is inside the admin group;
- `impersonation.stop` (`DELETE /impersonate`) is on **`auth` alone**.

By the time stop is called the session is a *member's*. Behind `admin` it would 403 the one request
that ends the impersonation; behind `verified` an administrator who impersonated an account that has
not verified its address would be stranded on the verification notice with no way back short of
clearing their cookies. The name matters as much as the middleware: `AdminAccessTest` sweeps the
route collection and requires all three of `auth`, `verified`, `admin` on every route named
`admin.*`, so naming this one `admin.impersonation.stop` would break it into exactly the shape that
does not work. There must be no route back to your own account that the impersonated account cannot
take.

## Only members can be impersonated, and an impersonated session never reaches `/admin`

`ImpersonationController::store()` refuses two targets: **yourself**, and **any administrator**.

Reaching a member hands an administrator nothing new — they can already delete that account and
change its role ([sessions.md](sessions.md)), so impersonation only reaches downward. Reaching a
*peer* would be a lateral move between equals: any administrator could act as any other, with
nothing in `users` recording that they did.

`EnsureUserIsAdmin` **separately** refuses any impersonated session, whatever role it holds. That
looks redundant today, because only members can be a target and a member fails the role check first.
It is not: a second administrator can promote the account while somebody is inside it, and without
this check that promotion silently upgrades a borrowed member session into a full administrator one.
The check is what makes "impersonation never reaches `/admin`" a property of the boundary rather than
a consequence of a rule enforced one controller away. Do not delete it as duplication.

## Deleted and demoted are the same case, and both end in `abandon()`

`stop()` handles the one account that can vanish mid-impersonation: an administrator's own. Deleting
an account removes its session rows, but the impersonating browser's row belongs to the **target** by
then, so it survives; **demoting** the account touches no session row at all. Neither leaves an
administrator to hand the browser back to, so both take `ImpersonationSession::abandon()` — logout,
invalidate, regenerate the token — and land on the login page with an error toast.

Checking only that the row still exists is the tempting half-fix and it is the dangerous one: it
signs a demoted account back in, which is exactly "handing the session to a non-admin". So the role
is re-checked on **every** lookup, in `findImpersonator()`: the session key records that an
administrator started this, not that they still are one. Losing the session costs one login; the two
alternatives cost an account.

## The banner is mounted at the app root, not in a layout

`resources/js/lib/impersonation-banner.ts` mounts `ImpersonationBanner.svelte` into its own container
appended to `document.body`, the same way `flash-toast.ts` mounts the toaster and for the same
reason — with more at stake. Layouts are resolved per page-name prefix in `resources/js/app.ts`, and
an impersonating administrator can reach every one of them: the app pages, settings, the email
verification notice under `AuthLayout`, the landing page under `PublicLayout`. A banner living in a
layout would vanish on whichever prefixes nobody remembered, and a banner that is only *sometimes* on
screen is worse than none — it teaches the administrator that its absence means they are themselves.

The banner reads `auth.impersonator` from the shared props, which `HandleInertiaRequests` builds by
hand as `{name, email}` and nothing else. `auth.user` is the impersonated account and is meant to be
complete; this is a *second* account appearing in the props of a session that is not its own, so it
carries only what the banner says out loud. `ImpersonationTest` pins the key set with a scoped
`has()` and no `->etc()`.

### A session that is impersonating always gets a banner, even with nobody to name

`presentImpersonator()` asks `isActive()`, **not** whether an administrator was found, and the two
fields are nullable while the object is not. That looks like a missing null check and is not: with
the administrator deleted or demoted mid-session, `impersonator()` returns null, and returning null
for the whole prop would remove the banner — the only exit — and strand the browser inside somebody
else's account with no way back. So `auth.impersonator` is non-null whenever `isActive()` is true,
the name and email are null when there is nobody to name, and the banner renders "an administrator"
in their place. Non-null answers "is this session impersonating"; `name` separately answers "by
whom", and may have no answer. An ordinary session still gets a plain `null`.

`can_impersonate` on the accounts screen is presentation only, exactly like `is_self` — the server
refuses the requester and every administrator whether or not a button was rendered.

## Testing an already-impersonating request

`phpunit.xml` sets `SESSION_DRIVER=array` and the harness sends no session cookie back, so a session
cannot be carried from one request to the next the way a browser would ([sessions.md](sessions.md)).
Build the request with `withSession([ImpersonationSession::SESSION_KEY => $admin->getKey()])`
instead — `tests/Feature/Admin/ImpersonationTest.php` has an `actingAsImpersonated()` helper. That is
also the more honest test: it asserts what the guards do with the key rather than what one particular
path happened to leave behind.

The trap: `withSession()` writes into the **application's** session store, which *does* outlive a
single request inside one test. A positive control run after an impersonated request in the same test
inherits the key and fails. Run controls first, or flush the session between them.
