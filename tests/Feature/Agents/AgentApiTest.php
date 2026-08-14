<?php

use App\Actions\Agents\IssueAgentCredential;
use App\Enums\GameRole;
use App\Models\AgentCredential;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The agent API
|--------------------------------------------------------------------------
|
| Everything under api/* is authenticated by a bearer token and nothing else: no session, no
| cookie, no CSRF. The sweep at the bottom is what keeps that true as endpoints are added —
| a route named api.* that forgets the middleware would be an unauthenticated window into a
| game, and it fails here without anybody remembering to write a case for it.
|
| The failures answer in two different ways on purpose. A missing or unrecognised token is a
| 401 and says nothing more, because a caller holding neither should not learn which it was.
| A recognised token whose seat has been retired, or whose game has been archived, is a 403
| naming the reason: that caller has already proved it holds a live credential, so nothing is
| left to leak — and the distinction is what stops an operator rotating a token that was never
| the problem.
|
*/

/**
 * A seated agent holding a usable token, returned as [seat, token].
 *
 * @return array{0: GameSeat, 1: string}
 */
function agentWithToken(?Game $game = null, ?GameSeat $seat = null): array
{
    $seat ??= GameSeat::factory()
        ->for($game ?? Game::factory()->create())
        ->for(User::factory()->agent())
        ->create();

    return [$seat, app(IssueAgentCredential::class)->handle($seat)];
}

test('a request with no token is refused', function () {
    $this->getJson(route('api.v1.me'))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Provide your agent token as a bearer token.');
});

test('a request with an unknown token is refused', function () {
    $this->withToken('svl_agent_nothing-was-ever-issued-like-this')
        ->getJson(route('api.v1.me'))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'That agent token is not valid.');
});

test('a session cookie is not a credential here', function () {
    /*
     * An administrator signed in to the browser must not be able to reach the agent surface just by
     * being signed in. The routes carry no `web` group, so there is no session for the request to
     * borrow — this asserts that the omission is real rather than assumed.
     */
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('api.v1.me'))
        ->assertUnauthorized();
});

test('a valid token identifies the seat it belongs to', function () {
    $game = Game::factory()->create(['name' => 'First Contact', 'short_name' => 'FC1']);
    $agent = User::factory()->agent()->create(['name' => 'Cartographer']);
    $seat = GameSeat::factory()->for($game)->for($agent)->create(['role' => GameRole::Player]);

    [, $token] = agentWithToken(seat: $seat);

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.agent.name', 'Cartographer')
        ->assertJsonPath('data.game.name', 'First Contact')
        ->assertJsonPath('data.game.short_name', 'FC1')
        ->assertJsonPath('data.seat.id', $seat->id)
        ->assertJsonPath('data.seat.role', 'player');
});

test('the agent address is not handed back to the agent', function () {
    [, $token] = agentWithToken();

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonMissingPath('data.agent.email');
});

test('a retired seat is refused, and told why', function () {
    [$seat, $token] = agentWithToken();

    /*
     * `forceFill` because `is_active` is deliberately not fillable — it changes only through the
     * retire and reactivate endpoints (see `App\Models\GameSeat`), so an `update()` here would
     * silently do nothing and the test would pass without a retired seat anywhere in it.
     */
    $seat->forceFill(['is_active' => false])->save();

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertForbidden()
        ->assertJsonPath('message', 'That seat has been retired.');
});

test('an archived game is refused, and told why', function () {
    $game = Game::factory()->archived()->create();

    [, $token] = agentWithToken($game);

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertForbidden()
        ->assertJsonPath('message', 'That game has been archived.');
});

test('using a token records that it was used', function () {
    [, $token] = agentWithToken();

    expect(AgentCredential::query()->firstOrFail()->last_used_at)->toBeNull();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertOk();

    expect(AgentCredential::query()->firstOrFail()->last_used_at)->not->toBeNull();
});

test('a deleted agent account takes its credential with it', function () {
    [$seat, $token] = agentWithToken();

    $seat->user->delete();

    expect(AgentCredential::query()->count())->toBe(0);

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('every route on the agent surface is behind the agent middleware and a throttle', function () {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'api.'));

    expect($apiRoutes)->not->toBeEmpty();

    $apiRoutes->each(function (RoutingRoute $route): void {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('agent')->toContain('throttle:agent');

        /*
         * The throttle has to come first, or a flood is rate-limited only after each request has
         * already cost a database lookup — which is the resource the limit exists to protect.
         */
        expect(array_search('throttle:agent', $middleware, true))
            ->toBeLessThan(array_search('agent', $middleware, true));
    });
});

test('the agent limiter bounds both the address and the token', function () {
    /*
     * The policy itself, asserted by invoking the registered limiter rather than by sending three
     * hundred requests. Two limits, because either alone is porous: a caller rotating a made-up token
     * gets a fresh token bucket every request, so only the address limit sees the flood; and one
     * runaway agent must not be able to spend the whole address budget of a NAT it shares.
     */
    $limiter = RateLimiter::limiter('agent');

    expect($limiter)->not->toBeNull();

    $request = Request::create('/api/v1/me');
    $request->headers->set('Authorization', 'Bearer svl_agent_example');
    $request->server->set('REMOTE_ADDR', '203.0.113.7');

    $limits = collect($limiter($request));

    expect($limits)->toHaveCount(2)
        ->and($limits->pluck('maxAttempts')->all())->toBe([300, 120]);

    /* The raw token must never become a cache key — the key holds a digest of it. */
    $keys = $limits->pluck('key')->all();

    expect($keys[0])->toBe('agent-ip:203.0.113.7')
        ->and($keys[1])->toBe('agent-token:'.hash('sha256', 'svl_agent_example'))
        ->and($keys[1])->not->toContain('svl_agent_example');
});

test('an agent that goes over the limit is turned away before the token is read', function () {
    /*
     * The wiring, proved with a limit of one rather than by replaying the real numbers. What matters
     * is that `throttle:agent` is genuinely mounted on the route and answers 429 — the size of the
     * bucket is the policy test above.
     *
     * The token is deliberately a bad one: a 429 rather than a 401 is what shows the throttle running
     * ahead of `AuthenticateAgent`, which is the ordering the database lookup is protected by.
     */
    RateLimiter::for('agent', fn (): Limit => Limit::perMinute(1)->by('test'));

    $this->withToken('svl_agent_wrong')->getJson(route('api.v1.me'))->assertUnauthorized();

    $this->withToken('svl_agent_wrong')
        ->getJson(route('api.v1.me'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('a throttled response is still json', function () {
    RateLimiter::for('agent', fn (): Limit => Limit::perMinute(1)->by('test'));

    [, $token] = agentWithToken();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertOk();

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertStatus(429)
        ->assertJsonStructure(['message']);
});

test('the agent surface carries no session middleware', function () {
    /*
     * Asserted positively rather than trusted to the routing configuration: a bearer-authenticated
     * surface that quietly acquired a session would start accepting a cookie as a credential, and
     * `AuthenticateAgent` would never see the difference.
     */
    $route = Route::getRoutes()->getByName('api.v1.me');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware() ?? [])
        ->not->toContain('web')
        ->not->toContain(StartSession::class);
});
