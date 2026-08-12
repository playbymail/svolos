<?php

use App\Enums\GameRole;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\GameSeatController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A game role is not an application role
|--------------------------------------------------------------------------
|
| `App\Enums\UserRole` (admin | member) and `App\Enums\GameRole` (player | gamemaster) are two
| deliberately unrelated systems, and this file is what stops a later change from wiring them
| together. Only `UserRole::Admin` opens `/admin`; a gamemaster seat opens nothing.
|
| The direction that matters is the escalation one. A game role is meant to be cheap to grant —
| handed out by whoever runs a game, to whoever they invite — while an application role reaches
| every account in the installation. Anything that lets the first imply the second turns "let me
| run a game" into a privilege escalation path, so the test that earns its place is the one where
| a **member holding a gamemaster seat** is refused everywhere.
|
| The other direction is asserted too, because a merge would break it symmetrically: an
| administrator holds no seat at any game merely by being an administrator.
|
| The route lists are built by sweeping the route collection rather than being written out, so a
| game admin route added later is covered here without anybody remembering to add a case.
|
*/

/*
 * `executableSourceOf()` — a class's source with comments stripped — lives in `tests/Pest.php`, because
 * the gamemaster side of this boundary asserts the mirror image of these rules and a helper declared in a
 * test file is only loaded when that file is.
 */

/**
 * Every route in the games area, as [method, url] pairs against a real game and seat.
 *
 * Built by sweeping the route collection so a later route cannot escape the check. `GET` routes are
 * requested as `GET`; everything else uses its first non-`HEAD` method.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function gameAreaRequests(Game $game, GameSeat $seat): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.games'))
        ->mapWithKeys(function (RoutingRoute $route) use ($game, $seat): array {
            $method = collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first();

            $parameters = [];

            if (in_array('game', $route->parameterNames(), true)) {
                $parameters['game'] = $game->getKey();
            }

            if (in_array('seat', $route->parameterNames(), true)) {
                $parameters['seat'] = $seat->getKey();
            }

            return [
                (string) $route->getName() => [
                    mb_strtolower((string) $method),
                    route((string) $route->getName(), $parameters),
                ],
            ];
        })
        ->all();
}

/**
 * A payload that would succeed for an administrator, so a 403 is never a validation failure in disguise.
 *
 * @return array<string, mixed>
 */
function gameAreaPayload(User $candidate): array
{
    return [
        'name' => 'Escalated Game',
        'short_name' => 'ESCALATE',
        /* Paused rather than active: an active game needs a generated world, and this payload has to
         * be one an administrator could really post, so that every 403 below is the gate refusing a
         * valid request rather than validation refusing an invalid one. */
        'status' => 'paused',
        'seed' => 1234,
        'user_id' => $candidate->id,
        'role' => GameRole::Gamemaster->value,
    ];
}

test('the sweep found every game admin route', function () {
    /*
     * A positive control. Without it, a change that renamed the routes would leave this file asserting
     * nothing at all while still reporting green.
     */
    $requests = gameAreaRequests(Game::factory()->create(), GameSeat::factory()->create());

    expect(array_keys($requests))->toEqualCanonicalizing([
        'admin.games.index',
        'admin.games.store',
        'admin.games.show',
        'admin.games.update',
        'admin.games.seed.update',
        'admin.games.destroy',
        'admin.games.seats.store',
        'admin.games.seats.role.update',
        'admin.games.seats.retire',
        'admin.games.seats.reactivate',
    ]);
});

test('a member holding a GAMEMASTER seat is forbidden from every game admin route', function () {
    /*
     * **The test this file exists for.** The gamemaster is a plain member of the installation, and holds
     * the highest game role there is at the very game the routes address. Every route must still refuse
     * them. If a future change ever consults a seat to decide access — or gives `GameRole::Gamemaster`
     * any application meaning — this is what fails.
     */
    $game = Game::factory()->create(['name' => 'Their Own Game', 'short_name' => 'THEIRS']);

    $gamemaster = User::factory()->create();
    $seat = GameSeat::factory()->for($game)->for($gamemaster)->gamemaster()->create();

    expect($gamemaster->role)->toBe(UserRole::Member);
    expect($gamemaster->isAdmin())->toBeFalse();
    expect($seat->role)->toBe(GameRole::Gamemaster);

    foreach (gameAreaRequests($game, $seat) as $name => [$method, $url]) {
        $this->actingAs($gamemaster)
            ->{$method}($url, gameAreaPayload($gamemaster))
            ->assertForbidden("gamemaster reached {$name}");
    }

    /* Nothing was created, changed or removed on the way to those 403s. */
    expect(Game::query()->count())->toBe(1);
    expect($game->fresh()?->name)->toBe('Their Own Game');
    /* Including the seed, which the payload also posted: this game is in setup, so only the 403 stopped it. */
    expect($game->fresh()?->seed)->toBe($game->seed);
    expect($game->seats()->count())->toBe(1);
    expect($seat->fresh()?->role)->toBe(GameRole::Gamemaster);
    expect($seat->fresh()?->is_active)->toBeTrue();
});

test('a gamemaster is forbidden from the rest of the admin area as well', function () {
    /*
     * The seat must not open a *neighbouring* door either — the accounts, invitations and sessions
     * screens are where a privilege escalation would actually pay off.
     */
    $gamemaster = User::factory()->create();
    GameSeat::factory()->for($gamemaster)->gamemaster()->create();

    foreach (['admin.index', 'admin.users.index', 'admin.invitations.index', 'admin.sessions.index'] as $name) {
        $this->actingAs($gamemaster)->get(route($name))->assertForbidden("gamemaster reached {$name}");
    }
});

test('a gamemaster seat does not make an account an administrator at the model level', function () {
    /*
     * Asserted below the HTTP layer as well, because a 403 today would still be a 403 if `isAdmin()`
     * started consulting seats but the middleware happened to be bypassed somewhere else.
     */
    $gamemaster = User::factory()->create();
    GameSeat::factory()->for($gamemaster)->gamemaster()->create();

    $gamemaster->refresh();

    expect($gamemaster->isAdmin())->toBeFalse();
    expect($gamemaster->role)->toBe(UserRole::Member);
    expect($gamemaster->role)->not->toBeInstanceOf(GameRole::class);
});

test('holding a gamemaster seat does not get past the admin middleware on its own', function () {
    Route::middleware(['auth', 'admin'])->get('/testing/gamemaster-escalation', fn () => 'reached');

    $gamemaster = User::factory()->create();
    GameSeat::factory()->for($gamemaster)->gamemaster()->create();

    $this->actingAs($gamemaster)->get('/testing/gamemaster-escalation')->assertForbidden();
});

test('being an administrator does not hand out a seat at any game', function () {
    /* The other direction. An application role says nothing about game membership. */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    expect($game->seats()->where('user_id', $admin->id)->exists())->toBeFalse();
    expect(GameSeat::query()->where('user_id', $admin->id)->exists())->toBeFalse();

    /* The administrator reaches the screen, and appears there as an account that could still be seated. */
    $this->actingAs($admin)->get(route('admin.games.show', ['game' => $game]))->assertOk();
});

test('an administrator who also holds a player seat is still an administrator', function () {
    /*
     * The two systems being unrelated cuts both ways: a *lesser* game role must not subtract from an
     * application role either.
     */
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    GameSeat::factory()->for($game)->for($admin)->create();

    $this->actingAs($admin)->get(route('admin.games.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
});

test('the admin middleware code mentions no game or seat', function () {
    /*
     * The rule in `.ai/rules/roles.md` is that `EnsureUserIsAdmin` must never consult a game or a seat.
     * A behavioural test cannot see a check that is present but currently redundant, so this reads the
     * class itself — it is the assertion that survives somebody adding a seat lookup that happens to
     * agree with `isAdmin()` today.
     */
    $code = executableSourceOf(EnsureUserIsAdmin::class);

    foreach (['Game', 'GameSeat', 'GameRole', 'game_seats', 'gamemaster'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }
});

test('game authorisation never consults the application role, and the two enums share nothing', function () {
    /*
     * The mirror of the rule above: the seat controller must not read `users.role`, and the two enums
     * must stay separate types with no shared case values that could let one be passed for the other.
     *
     * Comments are stripped first, because the controller's own doc block explains the separation by
     * naming `UserRole` — prose about the boundary is not a breach of it, and a check that could not
     * tell the two apart would push the explanation out of the file that needs it most.
     */
    $code = executableSourceOf(GameSeatController::class);

    expect($code)->not->toContain('isAdmin');
    expect($code)->not->toContain('UserRole');
    expect($code)->not->toContain('users.role');

    /* The positive control: the stripping left the actual code behind. */
    expect($code)->toContain('GameRole::Player');

    $userRoleValues = array_column(UserRole::cases(), 'value');
    $gameRoleValues = array_column(GameRole::cases(), 'value');

    expect(array_intersect($userRoleValues, $gameRoleValues))->toBeEmpty();
    expect(GameRole::tryFrom('admin'))->toBeNull();
    expect(GameRole::tryFrom('member'))->toBeNull();
    expect(UserRole::tryFrom('gamemaster'))->toBeNull();
    expect(UserRole::tryFrom('player'))->toBeNull();
});

test('the game role enum documents that it carries no application permissions', function () {
    /*
     * The task requires the statement to live in the enum itself, where somebody adding a case will read
     * it, rather than only in a rules file they may never open.
     */
    $doc = (new ReflectionEnum(GameRole::class))->getDocComment();

    expect($doc)->toBeString();
    expect((string) $doc)->toContain('no application permissions');
});
