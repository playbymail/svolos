<?php

use App\Models\Session;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The session identifier must not escape the server
|--------------------------------------------------------------------------
|
| A row's primary key in the `sessions` table *is* the value the browser holds in its session
| cookie. Anything that learns it can impersonate that browser for as long as the session lives,
| so it must never appear in an Inertia prop, in rendered HTML, or in a URL — and it must never
| be a route parameter, because URLs are written to history, proxy logs and referrer headers.
|
| This file is the acceptance criterion for that, and it is written to fail rather than to pass:
|
| - the whole page object is walked recursively, values *and* array keys, so a leak into a nested
|   prop or one used as a map key is caught rather than only the two fields somebody thought to
|   check;
| - the rendered HTML is searched as well as the decoded props, which covers a leak through an
|   attribute or a form field rather than a prop;
| - every identifier in the database is searched for, not just the interesting one;
| - and each screen asserts a **positive control** — that the digest, or the account's email, *is*
|   present in the same haystack. Without that, a test that stopped looking in the right place
|   (a renamed prop, a 500 rendered as an error page, an empty list) would keep passing while
|   proving nothing.
|
| Response *headers* are deliberately not searched: the encrypted session cookie is how a session
| works at all, and it is the one place the identifier is supposed to be.
|
*/

/**
 * Collect every string reachable inside a decoded page object, keyed by where it was found.
 *
 * Array keys are collected as well as scalar leaves. An identifier used to key a map would be
 * exactly as leaked as one used as a value, and a walk that only visited values would miss it.
 *
 * @return array<string, string>
 */
function stringsWithin(mixed $value, string $path = 'page'): array
{
    if (is_array($value)) {
        $strings = [];

        foreach ($value as $key => $item) {
            $childPath = $path.'.'.$key;

            if (is_string($key)) {
                $strings[$childPath.' [key]'] = $key;
            }

            $strings = [...$strings, ...stringsWithin($item, $childPath)];
        }

        return $strings;
    }

    if (is_scalar($value)) {
        return [$path => (string) $value];
    }

    return [];
}

/**
 * Find every place one of the given needles appears inside a decoded page object.
 *
 * @param  array<string, string>  $needles  label => needle
 * @return list<string>
 */
function leaksWithin(mixed $page, array $needles): array
{
    $leaks = [];

    foreach (stringsWithin($page) as $where => $text) {
        foreach ($needles as $label => $needle) {
            if (str_contains($text, $needle)) {
                $leaks[] = "{$label} appears at {$where}";
            }
        }
    }

    return $leaks;
}

test('the walk this file depends on really does find a planted value', function () {
    /*
     * The walk is the instrument every other test here measures with, so it is calibrated first.
     * If `stringsWithin()` silently returned nothing — a refactor, a wrong entry point, a typo in a
     * path — every leak assertion below would pass vacuously.
     */
    $page = [
        'props' => [
            'sessions' => [['digest' => 'abc', 'nested' => ['deep' => ['planted-value']]]],
            'map' => ['planted-key' => true],
            'notes' => 'a sentence containing planted-substring inside it',
        ],
        'url' => '/admin/sessions?x=planted-query',
    ];

    expect(leaksWithin($page, ['a leaf' => 'planted-value']))->not->toBeEmpty()
        ->and(leaksWithin($page, ['a key' => 'planted-key']))->not->toBeEmpty()
        ->and(leaksWithin($page, ['a substring' => 'planted-substring']))->not->toBeEmpty()
        ->and(leaksWithin($page, ['a url' => 'planted-query']))->not->toBeEmpty()
        ->and(leaksWithin($page, ['something absent' => 'never-planted']))->toBe([]);
});

test('no raw session identifier appears in any inertia prop, url or rendered page', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $member = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    $currentSessionId = $this->pinSessionId();

    $current = Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    $memberSession = Session::factory()->for($member)->create();
    $guestSession = Session::factory()->guest()->create();

    $identifiers = [
        "the administrator's own session identifier" => $currentSessionId,
        "the member's session identifier" => $memberSession->id,
        "a guest session's identifier" => $guestSession->id,
    ];

    /* The identifiers must be distinguishable from the digests for any of this to mean anything. */
    expect($currentSessionId)->toBe($current->id)
        ->and($current->digest())->not->toBe($current->id);

    /*
     * Each screen is paired with a string that must be in its page object. The two landing screens
     * have no props of their own, so their control is the shared `auth.user` prop — still enough to
     * prove the page rendered rather than 500ing into an error page with empty props.
     */
    $screens = [
        'admin/sessions/Index' => [route('admin.sessions.index'), $current->digest()],
        'admin/users/Index' => [route('admin.users.index'), 'grace@example.com'],
        'admin/Index' => [route('admin.index'), 'ada@example.com'],
        'Dashboard' => [route('dashboard'), 'ada@example.com'],
    ];

    foreach ($screens as $component => [$url, $positiveControl]) {
        $response = $this->actingAs($admin)->get($url);

        $response->assertOk();

        $page = $response->viewData('page');
        $html = $response->getContent();

        expect($page)->toBeArray()
            ->and($page['component'])->toBe($component);

        expect(leaksWithin($page, $identifiers))
            ->toBe([], "A session identifier reached the props or url of {$component}.");

        foreach ($identifiers as $label => $identifier) {
            expect(str_contains((string) $html, $identifier))
                ->toBeFalse("{$label} was rendered into the HTML of {$component}.");
        }

        /*
         * The positive control. Proves the haystack really is the page that was asked for and that
         * the search above was looking at something, rather than at an empty array or an error page.
         */
        expect(leaksWithin($page, ['the positive control' => $positiveControl]))
            ->not->toBeEmpty("The positive control was missing from {$component}, so the leak check above proved nothing.");

        expect(str_contains((string) $html, $positiveControl))
            ->toBeTrue("The positive control was missing from the HTML of {$component}.");
    }
});

test('the sessions screen sends digests and nothing that could be an identifier', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $currentSessionId = $this->pinSessionId();
    $current = Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    $memberSession = Session::factory()->for($member)->create();

    $page = $this->actingAs($admin)->get(route('admin.sessions.index'))->viewData('page');

    $sessions = $page['props']['sessions'];

    expect($sessions)->toHaveCount(2);

    foreach ($sessions as $session) {
        /*
         * An exact key set, not merely "no `id` key". A leak arrives under whatever name the person
         * adding it chose, so the guard has to be a whitelist.
         */
        expect(array_keys($session))->toBe([
            'digest',
            'user_name',
            'user_email',
            'ip_address',
            'browser',
            'platform',
            'last_active_at',
            'last_active_at_diff',
            'is_current',
        ]);

        expect($session['digest'])->toMatch('/^[0-9a-f]{64}$/');
    }

    /* Both digests are present, so the rows really were rendered. */
    expect(array_column($sessions, 'digest'))
        ->toContain($current->digest())
        ->toContain($memberSession->digest());
});

test('the accounts screen sends nothing that could be a session identifier', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    Session::factory()->count(2)->for($member)->create();

    $page = $this->actingAs($admin)->get(route('admin.users.index'))->viewData('page');

    foreach ($page['props']['users'] as $user) {
        expect(array_keys($user))->toBe([
            'id',
            'name',
            'email',
            'role',
            'role_label',
            'email_verified',
            'two_factor_enabled',
            'sessions_count',
            'created_at',
            'created_at_diff',
            'is_self',
        ]);
    }

    expect(array_column($page['props']['users'], 'sessions_count'))->toContain(2);
});

test('no session route takes a route parameter', function () {
    /*
     * The structural half of the rule. A `{session}` parameter would put the identifier — or force
     * somebody to invent a second lookup key for it — into the URL, so the parameter list has to
     * stay empty. Signing a session out therefore carries its digest in the request body.
     */
    $sessionRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.sessions.'));

    expect($sessionRoutes)->toHaveCount(3);

    $sessionRoutes->each(function (RoutingRoute $route): void {
        expect($route->parameterNames())->toBe([]);
    });
});

test('no url the application generates for the session screen carries an identifier', function () {
    $admin = User::factory()->admin()->create();

    $currentSessionId = $this->pinSessionId();
    $session = Session::factory()->for($admin)->create(['id' => $currentSessionId]);

    $urls = [
        route('admin.sessions.index'),
        route('admin.sessions.destroy'),
        route('admin.sessions.destroy-others'),
        route('admin.users.index'),
    ];

    foreach ($urls as $url) {
        expect($url)->not->toContain($session->id)
            ->and($url)->not->toContain($session->digest());
    }
});

test('the redirect after signing a session out carries no identifier', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Grace Hopper']);

    $currentSessionId = $this->pinSessionId();
    Session::factory()->for($admin)->create(['id' => $currentSessionId]);
    $target = Session::factory()->for($member)->create();

    $targetId = $target->id;

    $response = $this->actingAs($admin)
        ->delete(route('admin.sessions.destroy'), ['digest' => $target->digest()])
        ->assertRedirect(route('admin.sessions.index'));

    expect($response->headers->get('Location'))->not->toContain($targetId);

    /* And the flash the next page carries, which names the account rather than the session. */
    $page = $this->actingAs($admin)->get(route('admin.sessions.index'))->viewData('page');

    expect(leaksWithin($page, ['the signed out identifier' => $targetId]))->toBe([])
        ->and(leaksWithin($page, ['the current identifier' => $currentSessionId]))->toBe([])
        ->and(leaksWithin($page, ['the positive control' => 'Grace Hopper was signed out of that browser.']))->not->toBeEmpty();
});

test('the model keeps the identifier out of serialisation even if a controller hands it over whole', function () {
    /*
     * Defence in depth for the mistake this file exists to catch: somebody passing the model, or a
     * collection of them, straight into `Inertia::render()`. `#[Hidden]` means that still does not
     * emit an identifier. This is a backstop, not the rule — the rule is that presenters build the
     * array by hand.
     */
    $session = Session::factory()->create();

    expect(json_encode($session))->not->toContain($session->id)
        ->and(json_encode(Session::query()->get()))->not->toContain($session->id)
        ->and(json_encode($session))->not->toContain($session->payload)
        ->and(json_encode($session))->toContain($session->ip_address);
});
