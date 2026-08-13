<?php

use App\Models\AgentCredential;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Issuing and revoking an agent's token
|--------------------------------------------------------------------------
|
| The token is the whole of an agent's credential, so the properties asserted here are the
| ones that make it safe to hand out:
|
| - the plain text is stored **nowhere**. The column holds a sha256 of it, and the only copy
|   that ever existed leaves in the flash bag;
| - it is therefore shown exactly once. Flash data rides on the response after the redirect
|   and is gone on the next request, which is what makes "once" a property of the transport
|   rather than a promise the screen keeps;
| - minting again **rotates**. There is no way to recover a plain text, so replacing it is the
|   only revocation there is, and the previous token has to stop working for that to be true.
|
| Who may do it is the other half. Seating an agent is a gamemaster's business, but minting a
| credential is an administrator's, so these routes sit behind `admin` while the seat routes
| do not.
|
*/

/**
 * An agent with an active seat at a game, returned as [agent, seat].
 *
 * @return array{0: User, 1: GameSeat}
 */
function seatedAgent(?Game $game = null): array
{
    $agent = User::factory()->agent()->create();
    $seat = GameSeat::factory()->for($game ?? Game::factory()->create())->for($agent)->create();

    return [$agent, $seat];
}

/**
 * Read the plain-text token out of the response that minted it.
 *
 * There is nowhere else to get it: the column holds a digest, and the flash bag is the only place
 * the plain text is ever written. A test wanting to authenticate with a token has to catch it here,
 * exactly as an administrator has to copy it off the screen.
 */
function mintedAgentToken(TestResponse $response): string
{
    $response->assertInertiaFlash('agentToken');

    /*
     * Read through the `session()` helper rather than `$response->session()`, which is protected on
     * `TestResponse` and reachable only from inside Inertia's own macros.
     */
    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect($flash)->toBeArray()->toHaveKey('agentToken');

    $token = is_array($flash) ? ($flash['agentToken']['token'] ?? null) : null;

    expect($token)->toBeString();

    return (string) $token;
}

test('an administrator mints a token and is shown it once', function () {
    [$agent, $seat] = seatedAgent();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.credential.store', [$agent, $seat]));

    $response->assertRedirect(route('admin.agents.show', $agent));
    $response->assertInertiaFlash('agentToken');

    $token = mintedAgentToken($response);
    $credential = AgentCredential::query()->where('game_seat_id', $seat->id)->firstOrFail();

    expect($token)->toStartWith(AgentCredential::TOKEN_PREFIX)
        ->and($credential->token)->toBe(AgentCredential::hashToken($token))
        ->and($credential->token)->not->toBe($token);

    /*
     * The next request is where "once" is proved. Flash data survives exactly one response, so an
     * administrator who reloads has genuinely lost the only copy that ever existed — the screen
     * still knows a token *exists*, and nothing anywhere can say what it is.
     */
    $this->get(route('admin.agents.show', $agent))
        ->assertOk()
        ->assertInertiaFlashMissing('agentToken')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/agents/Show')
            ->where('seats.0.has_credential', true)
        );
});

test('the plain text token is never stored', function () {
    [$agent, $seat] = seatedAgent();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.credential.store', [$agent, $seat]));

    $credential = AgentCredential::query()->firstOrFail();

    /*
     * A stored plain text would be exactly 58 characters (the prefix plus 48 random ones); a stored
     * digest is exactly 64 hex characters. Asserting the shape catches the mistake even if a future
     * change alters how the token is generated.
     */
    expect($credential->token)->toHaveLength(64)
        ->and($credential->token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($credential->token)->not->toStartWith(AgentCredential::TOKEN_PREFIX);
});

test('minting again invalidates the previous token', function () {
    [$agent, $seat] = seatedAgent();
    $admin = User::factory()->admin()->create();

    $first = mintedAgentToken(
        $this->actingAs($admin)->post(route('admin.agents.credential.store', [$agent, $seat]))
    );

    $second = mintedAgentToken(
        $this->actingAs($admin)->post(route('admin.agents.credential.store', [$agent, $seat]))
    );

    $credential = AgentCredential::query()->firstOrFail();

    expect(AgentCredential::query()->count())->toBe(1)
        ->and($second)->not->toBe($first)
        ->and($credential->token)->toBe(AgentCredential::hashToken($second));

    /*
     * The real proof is at the door rather than in the column: the first token no longer
     * authenticates. Rotation is the only revocation there is, so this is the assertion that makes
     * "mint again to revoke" a true statement rather than a hopeful one.
     */
    $this->withToken($first)->getJson(route('api.v1.me'))->assertUnauthorized();
    $this->withToken($second)->getJson(route('api.v1.me'))->assertOk();
});

test('the issuing administrator is recorded and the clock is reset', function () {
    [$agent, $seat] = seatedAgent();
    $admin = User::factory()->admin()->create(['name' => 'Quartermaster']);

    AgentCredential::factory()->for($seat)->used()->create();

    $this->actingAs($admin)->post(route('admin.agents.credential.store', [$agent, $seat]));

    $credential = AgentCredential::query()->firstOrFail();

    expect($credential->issued_by_id)->toBe($admin->id)
        ->and($credential->last_used_at)->toBeNull();
});

test('a retired seat is refused a token', function () {
    $agent = User::factory()->agent()->create();
    $seat = GameSeat::factory()->for(Game::factory())->for($agent)->retired()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.credential.store', [$agent, $seat]))
        ->assertForbidden();

    expect(AgentCredential::query()->count())->toBe(0);
});

test('a seat belonging to somebody else is not reachable through an agent url', function () {
    [$agent] = seatedAgent();
    [, $otherSeat] = seatedAgent();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.credential.store', [$agent, $otherSeat]))
        ->assertNotFound();
});

test('a person seat cannot be issued an agent token', function () {
    $person = User::factory()->create();
    $seat = GameSeat::factory()->for(Game::factory())->for($person)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.credential.store', [$person, $seat]))
        ->assertNotFound();
});

test('revoking deletes the credential and leaves the seat alone', function () {
    [$agent, $seat] = seatedAgent();
    AgentCredential::factory()->for($seat)->create();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.agents.credential.destroy', [$agent, $seat]));

    $response->assertRedirect(route('admin.agents.show', $agent));

    expect(AgentCredential::query()->count())->toBe(0)
        ->and($seat->fresh()?->is_active)->toBeTrue();
});

test('revoking a credential that is not there is a 404', function () {
    [$agent, $seat] = seatedAgent();

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.agents.credential.destroy', [$agent, $seat]))
        ->assertNotFound();
});

test('a gamemaster cannot mint a token for an agent in their own game', function () {
    $game = Game::factory()->create();
    [$agent, $seat] = seatedAgent($game);

    /*
     * The gamemaster of this very game, so what is being refused is the *privilege* rather than the
     * game: they may seat this agent, and may not hand it a credential.
     */
    $this->actingAs(gamemasterOf($game))
        ->post(route('admin.agents.credential.store', [$agent, $seat]))
        ->assertForbidden();

    expect(AgentCredential::query()->count())->toBe(0);
});

test('a guest is redirected and a member is forbidden', function () {
    [$agent, $seat] = seatedAgent();

    $this->post(route('admin.agents.credential.store', [$agent, $seat]))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->post(route('admin.agents.credential.store', [$agent, $seat]))
        ->assertForbidden();
});
