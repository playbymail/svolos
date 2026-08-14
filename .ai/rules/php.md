# PHP and Laravel conventions

Globs: `app/**`

- Curly braces on every control structure, even single-line bodies.
- Constructor property promotion: `public function __construct(private readonly Foo $foo) {}`.
  No empty zero-parameter `__construct()` methods unless the constructor is private.
- Explicit return types and parameter type hints on every method.
- TitleCase enum cases. Every enum that reaches the UI gets a `label(): string` method, so the
  frontend never has to map raw case values to human strings.
- PHPDoc blocks over inline comments; inline comments only for genuinely non-obvious logic. Use
  array shape types in PHPDoc where the shape matters.
- Generate files with `php artisan make:*` and `--no-interaction` so they land in the right place
  with the framework's current stubs.
- Validation lives in Form Requests, never inline in controllers. Shared rule sets go in a
  `App\Concerns\*ValidationRules` trait (see `app/Concerns/ProfileValidationRules.php`).
- Controllers return `Inertia::render(...)` for screens and `to_route(...)` / `back()` for redirects.
  User feedback goes through `Inertia::flash('toast', ['type' => ..., 'message' => __(...)])`, which
  the frontend picks up in `resources/js/lib/flash-toast.ts`.
- Prefer named routes and `route()` over hardcoded URLs.
- Run `vendor/bin/pint --dirty` before finishing. Never weaken `pint.json` to make a file pass.

## Reading the authenticated user: `authenticatedUser()` or `?->`, never a blind call

PHPStan runs at level 8 (see [general.md](general.md)), and `Request::user()` is typed `?User` — it
cannot know a route is behind `auth` — so `$request->user()->anything()` is an error. There are
exactly two sanctioned ways to read it, and which one to use is decided by what a null would mean:

- **You need the model itself** (fill, save, delete, a relation, an update): take it from
  `App\Http\Controllers\Controller::authenticatedUser($request)`, which narrows with `instanceof` and
  throws `AuthenticationException` otherwise. That is not an assertion dressed up as a fix: the
  exception is real behaviour, and a guard that somehow resolved nothing leaves through the ordinary
  unauthenticated redirect instead of a type error mid-action. Assign it to a local once at the top of
  the method and use the local — don't call it repeatedly.
- **A null propagates to a correct outcome on its own**: use `$request->user()?->…`. An ownership
  comparison is already false against `null`, so `abort_unless($passkey->user_id === $request->user()?->getKey(), 403)`
  fails closed (`PasskeyController`). `ProfileUpdateRequest` passes `$this->user()?->id` into
  `profileRules(?int $userId)`, whose null branch is the *stricter* rule set — the uniqueness check
  simply stops ignoring the current row. Form Requests have no `authenticatedUser()` and don't need
  one for this reason.

Never reach level 8 with a suppression: no baseline, no `ignoreErrors`, no `@phpstan-ignore`, no
inline `@var`, no `assert()`, and no widening a parameter or return type to make an error go away.

## `sortBy()` on more than one key takes **one** closure returning a tuple

`$collection->sortBy([$a, $b])` reads like two key extractors and is not. Given an *array* of
comparisons, Laravel treats a callable one as a full **comparator** and calls it `$prop($a, $b)`; the
array form's key-extractor shape is `[['column', 'asc'], …]`, with strings. So a single-parameter
closure quietly takes the first argument, ignores the second, and returns a position as though it
were a comparison result — and the collection comes back in an arbitrary order.

Sort on several keys like this instead:

```php
->sortBy(fn (Asset $asset): array => [$assignmentIndex, $typeIndex])
```

One callable is a value retriever, and PHP compares equal-length arrays element by element, so the
tuple orders by the first key and then the second.

**Nothing type-checks this and nothing throws.** PHPStan sees a valid call, and PHP does not object
to extra arguments passed to a userland closure, so the only symptom is order — which a test
asserting the *first* element passes straight through. Assert the whole sequence, or the property the
reader actually depends on. It has already cost once: an interleaved list reached a keyed Svelte
`{#each}` and blanked a panel (see [assets.md](assets.md)).

## Testing

- Every change is programmatically tested. Pest feature tests by default; unit tests only where
  there is no HTTP surface.
- Use model factories, with named states for meaningful variants (`->admin()`, `->archived()`,
  `->inactive()`).
- Assert Inertia props with
  `assertInertia(fn (AssertableInertia $page) => $page->component(...)->has(...)->where(...))` —
  asserting only `assertOk()` does not prove the right page rendered.
- Test the authorisation boundary on every protected route: guest, member, administrator, and
  self-targeting where a self-target is forbidden.
- Never delete or weaken an existing test to make a change pass.
- `Inertia::flash()` does **not** land in props. It goes to the session, and the *next* Inertia
  response carries it in the page object's `flash` bag. Assert it with `assertInertiaFlash('toast',
  [...])` on the redirect response, and/or `hasFlash('toast', [...])` inside `assertInertia` after
  following the redirect — `$page->has('toast')` will never find it. See
  `tests/Feature/AppShellTest.php`.
- `tests/Pest.php` binds `Tests\TestCase` and `RefreshDatabase` to the `Feature` suite only. The
  `Unit` suite is listed in `phpunit.xml` and kept alive by `tests/Unit/.gitkeep`; deleting that file
  makes PHPUnit abort with `Test directory ... not found`.
