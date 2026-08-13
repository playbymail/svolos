<?php

use App\Actions\Agents\CreateAgent;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The agents administration screen
|--------------------------------------------------------------------------
|
| An agent is an ordinary account that plays by itself, so most of what is asserted here is
| that it really is ordinary — it takes seats, it holds a game role, and nothing in the game
| treats it specially. What is *not* ordinary is how it authenticates, and that is what earns
| the account a screen of its own: every column on the accounts screen describes how a person
| reaches an account, and an agent has none of them.
|
| The creation contract is the other half. An agent gets a verified address on a reserved
| domain and a password nobody has ever seen, because there is no mailbox to confirm and
| nobody to sign in. `is_agent` is assigned by the action and is not a request field — the
| same treatment `role` gets, for the same reason.
|
*/

/**
 * Every route on the screen, as [method, url factory] pairs.
 *
 * @return array<string, array{0: string, 1: Closure(User): string}>
 */
function agentAdminRoutes(): array
{
    return [
        'index' => ['get', fn (User $agent): string => route('admin.agents.index')],
        'create' => ['get', fn (User $agent): string => route('admin.agents.create')],
        'store' => ['post', fn (User $agent): string => route('admin.agents.store')],
        'show' => ['get', fn (User $agent): string => route('admin.agents.show', $agent)],
    ];
}

test('a guest is redirected to login', function (string $method, Closure $url) {
    $agent = User::factory()->agent()->create();

    $this->{$method}($url($agent))->assertRedirect(route('login'));
})->with(agentAdminRoutes());

test('a member is forbidden', function (string $method, Closure $url) {
    $agent = User::factory()->agent()->create();

    $this->actingAs(User::factory()->create())
        ->{$method}($url($agent))
        ->assertForbidden();
})->with(agentAdminRoutes());

test('an administrator sees every agent and no member', function () {
    $agent = User::factory()->agent()->create(['name' => 'Cartographer']);
    User::factory()->create(['name' => 'A Person']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.agents.index'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/agents/Index')
        ->has('agents', 1)
        ->where('agents.0.id', $agent->id)
        ->where('agents.0.name', 'Cartographer')
        ->where('agents.0.credentials_count', 0)
        ->where('agents.0.last_used_at_diff', null)
    );
});

test('the accounts screen leaves agents out', function () {
    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.users.index'));

    $response->assertOk();

    /*
     * Asserted by identity rather than by count: the point is that this specific account is
     * absent, not that some number of rows came back.
     */
    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/users/Index')
        ->where('users', fn (Collection $users): bool => $users
            ->pluck('id')
            ->doesntContain($agent->id))
    );
});

test('an administrator creates an agent with a derived address', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.store'), ['name' => 'The Cartographer']);

    $agent = User::query()->where('name', 'The Cartographer')->firstOrFail();

    $response->assertRedirect(route('admin.agents.show', $agent));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'The Cartographer was created. Seat it at a game to issue a token.',
    ]);

    expect($agent->isAgent())->toBeTrue()
        ->and($agent->isAdmin())->toBeFalse()
        ->and($agent->email)->toBe('the-cartographer@'.CreateAgent::DOMAIN)
        ->and($agent->hasVerifiedEmail())->toBeTrue();
});

test('a given address is used instead of a derived one', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.store'), [
            'name' => 'Surveyor',
            'email' => 'surveyor@somewhere.test',
        ]);

    expect(User::query()->where('email', 'surveyor@somewhere.test')->exists())->toBeTrue();
});

test('a second agent of the same name gets a distinct address', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.agents.store'), ['name' => 'Scout']);
    $this->actingAs($admin)->post(route('admin.agents.store'), ['name' => 'Scout']);

    $addresses = User::query()->where('is_agent', true)->pluck('email');

    expect($addresses)->toHaveCount(2)
        ->and($addresses->unique())->toHaveCount(2)
        ->and($addresses->first())->toBe('scout@'.CreateAgent::DOMAIN);
});

test('an agent password is not one anybody could guess', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.store'), ['name' => 'Quiet']);

    $agent = User::query()->where('name', 'Quiet')->firstOrFail();

    expect(Hash::check('password', $agent->password))->toBeFalse()
        ->and(Hash::check('', $agent->password))->toBeFalse();
});

test('an address already in use is rejected', function () {
    $existing = User::factory()->create();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.store'), [
            'name' => 'Clash',
            'email' => $existing->email,
        ]);

    $response->assertSessionHasErrors('email');

    expect(User::query()->where('name', 'Clash')->exists())->toBeFalse();
});

test('a name is required', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.agents.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('is_agent cannot be set from request input', function () {
    /*
     * The column is not fillable and the action assigns it, so posting it is not merely ignored on
     * this route — it could not promote an ordinary account anywhere else either. Asserted here
     * because this is the only screen that creates one.
     */
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('profile.update'), [
            'name' => 'Still A Person',
            'email' => 'person@example.test',
            'is_agent' => true,
        ]);

    expect(User::query()->where('email', 'person@example.test')->firstOrFail()->isAgent())->toBeFalse();
});

test('a person account is not addressable as an agent', function () {
    $person = User::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.agents.show', $person))
        ->assertNotFound();
});

test('an agent detail screen lists its seats', function () {
    $agent = User::factory()->agent()->create();
    $game = Game::factory()->create(['name' => 'First Contact']);
    GameSeat::factory()->for($game)->for($agent)->create();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.agents.show', $agent));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/agents/Show')
        ->where('agent.id', $agent->id)
        ->has('seats', 1)
        ->where('seats.0.game.name', 'First Contact')
        ->where('seats.0.has_credential', false)
        ->where('seats.0.can_issue', true)
    );
});
