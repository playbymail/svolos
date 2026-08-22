<?php

use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the shared inertia props expose the app name and a null user for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('name', config('app.name'))
            ->where('auth.user', null)
            ->where('sidebarOpen', true)
        );
});

test('the shared inertia props expose the signed in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('name', config('app.name'))
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
        );
});

test('the shared user prop never leaks the password hash or remember token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
        );
});

test('the sidebar starts open when the browser has sent no sidebar_state cookie', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sidebarOpen', true));
});

test('the sidebar_state cookie decides the sidebarOpen prop', function (string $cookie, bool $expected) {
    $this->withUnencryptedCookie('sidebar_state', $cookie)
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sidebarOpen', $expected));
})->with([
    'collapsed' => ['false', false],
    'expanded' => ['true', true],
]);

test('a controller flashing a toast makes it available where the frontend reads it', function () {
    $user = User::factory()->create();

    $expected = ['type' => 'success', 'message' => 'Profile updated.'];

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Player',
        'email' => $user->email,
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertInertiaFlash('toast', $expected);

    /*
     * Inertia::flash() only reaches the browser on the response after the redirect, in the page
     * object's `flash` bag rather than its props — that is the bag resources/js/lib/flash-toast.ts
     * subscribes to, so following the redirect is what proves the payload actually arrives.
     */
    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->hasFlash('toast', $expected)
        );
});

test('the sidebar links documentation at this application docs page, not the starter kit', function () {
    /*
     * The sidebar nav is built client-side, so there is no prop or HTTP surface to assert against and
     * this reads the component source instead. It is deliberately narrow — it pins the decision (our
     * own /docs, reached without leaving the app) rather than any markup, so restyling the sidebar
     * cannot break it while reintroducing a starter-kit link will.
     */
    $sidebar = file_get_contents(resource_path('js/components/AppSidebar.svelte'));

    expect($sidebar)
        ->toContain('docs()')
        ->not->toContain('laravel.com')
        ->not->toContain('github.com/laravel/svelte-starter-kit');

    // And the helper it imports resolves to a route this application actually serves.
    expect(route('docs', absolute: false))->toBe('/docs');
});

test('the sidebar footer can render an internal link without forcing a new tab', function () {
    /*
     * NavFooter used to hardcode target="_blank" on every item, which would have opened /docs in a new
     * tab and bypassed Inertia entirely. Opening a new tab is now opt-in per item via NavItem.external.
     */
    $navFooter = file_get_contents(resource_path('js/components/NavFooter.svelte'));

    expect($navFooter)
        ->toContain('{#if item.external}')
        ->toContain("import { Link } from '@inertiajs/svelte';");
});

test('a page with nothing flashed carries no toast', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('toast'));
});

/*
|--------------------------------------------------------------------------
| The kit template library's way in
|--------------------------------------------------------------------------
|
| `/gamemaster/kit-templates` shipped with no link to it anywhere — the routes, the screens and the
| gate were all there, and the only way to reach the library was to type the URL. The sidebar now
| offers it, hidden from accounts that would be refused, and these pin the question it is hidden on.
|
| That question is **seats, never `users.role`** (see `.ai/rules/roles.md`), which is why an
| administrator who runs no game is one of the cases below rather than an obvious pass.
*/

test('the shared props tell a gamemaster they run a game', function () {
    $gamemaster = gamemasterOf(Game::factory()->create());

    $this->actingAs($gamemaster)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.runsAGame', true));
});

test('the shared props withhold the kit library from accounts that would be refused it', function (Closure $make) {
    $user = $make();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.runsAGame', false));
})->with([
    'a member with no seat anywhere' => [fn (): User => User::factory()->create()],

    'a player, whose seat runs nothing' => [function (): User {
        $player = User::factory()->create();
        GameSeat::factory()->for(Game::factory())->for($player)->create();

        return $player;
    }],

    /*
     * The case the whole arrangement turns on. An administrator holds every application permission
     * there is and still runs no game, so the link must not appear — `runs-a-game` would refuse them
     * at the door, and a prop computed off `users.role` would offer them a 403.
     */
    'an administrator holding no seat' => [fn (): User => User::factory()->admin()->create()],

    /*
     * Seats are retired rather than deleted, so a former gamemaster still has the row. `is_active` is
     * the whole difference between running a game and having run one.
     */
    'a retired gamemaster' => [function (): User {
        $former = User::factory()->create();
        GameSeat::factory()->for(Game::factory())->for($former)->gamemaster()->retired()->create();

        return $former;
    }],
]);

test('a guest is told they run nothing rather than being asked the question', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user', null)
            ->where('auth.runsAGame', false)
        );
});

test('the sidebar offers the kit template library, gated on that prop', function () {
    /*
     * The nav is built client-side, so as with the documentation link above there is no HTTP surface
     * to assert against and this reads the component source. Narrow on purpose: it pins that the item
     * exists, that it is hidden behind `runsAGame`, and that its href comes from Wayfinder rather than
     * a hardcoded string — restyling the sidebar cannot break it, deleting the link can.
     */
    $sidebar = file_get_contents(resource_path('js/components/AppSidebar.svelte'));

    expect($sidebar)
        ->toContain("from '@/routes/gamemaster/kit-templates'")
        ->toContain('kitTemplatesIndex()')
        ->toContain('auth.runsAGame')
        ->not->toContain("'/gamemaster/kit-templates'");

    // And the helper it imports resolves to a route this application actually serves.
    expect(route('gamemaster.kit-templates.index', absolute: false))->toBe('/gamemaster/kit-templates');
});
