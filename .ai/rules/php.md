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
- `tests/Pest.php` binds `Tests\TestCase` and `RefreshDatabase` to the `Feature` suite only. The
  `Unit` suite is listed in `phpunit.xml` and kept alive by `tests/Unit/.gitkeep`; deleting that file
  makes PHPUnit abort with `Test directory ... not found`.
