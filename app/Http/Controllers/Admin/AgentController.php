<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Agents\CreateAgent;
use App\Concerns\PresentsAgents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgentStoreRequest;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's view of the accounts that play by themselves.
 *
 * Agents are ordinary accounts holding ordinary seats — the game cannot tell them apart from people,
 * and that is the design (see `.ai/rules/agents.md`). They get a screen of their own anyway, because
 * everything the *accounts* screen exists to do is about how a person reaches an account: verifying
 * an address, counting live browsers, changing a role, signing in as somebody. An agent has none of
 * that, and both of that screen's writes refuse one outright, so listing agents there would mean
 * offering controls that 403 next to columns that are permanently blank.
 *
 * Seating is deliberately **not** here. An agent takes a seat through the game's own roster screen,
 * like any other account, because a roster is a property of a game rather than of the account being
 * added to it — and a gamemaster is allowed to do it. Minting the credential is what stays behind
 * `admin`: a bearer token is an account-level secret, not a decision about one game's roster.
 */
class AgentController extends Controller
{
    use PresentsAgents;

    /**
     * List every agent account, with the seats it holds and the state of each seat's credential.
     *
     * The eager load is the whole query budget: seats, their games and their credentials in three
     * queries rather than three per agent.
     */
    public function index(): Response
    {
        $agents = User::query()
            ->where('is_agent', true)
            ->with(['gameSeats.game', 'gameSeats.agentCredential'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $agent): array => $this->presentAgent($agent))
            ->values()
            ->all();

        return Inertia::render('admin/agents/Index', [
            'agents' => $agents,
        ]);
    }

    /**
     * Show the form for creating an agent.
     */
    public function create(): Response
    {
        return Inertia::render('admin/agents/Create', [
            'emailDomain' => CreateAgent::DOMAIN,
        ]);
    }

    /**
     * Create an agent account.
     *
     * The redirect goes to the new agent rather than back to the list, because an agent with no seat
     * and no token cannot do anything yet and its own screen is where both are explained.
     */
    public function store(AgentStoreRequest $request, CreateAgent $createAgent): RedirectResponse
    {
        $agent = $createAgent->handle(
            $request->string('name')->toString(),
            $request->filled('email') ? $request->string('email')->toString() : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was created. Seat it at a game to issue a token.', ['name' => $agent->name]),
        ]);

        return to_route('admin.agents.show', $agent);
    }

    /**
     * Show one agent, its seats, and what can be done about each seat's credential.
     *
     * A non-agent account is a **404** rather than a 403: this screen addresses a collection that a
     * person's account is not in, and saying "forbidden" would confirm that the id belongs to
     * somebody while telling an administrator nothing they can act on.
     */
    public function show(User $user): Response
    {
        abort_unless($user->isAgent(), 404);

        $user->load(['gameSeats.game', 'gameSeats.agentCredential.issuedBy']);

        return Inertia::render('admin/agents/Show', [
            'agent' => $this->presentAgent($user),
            'seats' => $user->gameSeats
                ->sortBy(fn (GameSeat $seat): string => $seat->game->name)
                ->map(fn (GameSeat $seat): array => $this->presentSeat($seat))
                ->values()
                ->all(),
            'assignableGames' => $this->assignableGames($user),
        ]);
    }

    /**
     * List the games this agent could still be seated at.
     *
     * The mirror of `Admin\GameController::assignableAccounts()`, and it excludes seats **retired** as
     * well as active for the same reason: an account that left a game owns the row that occupies its
     * place in the unique index on `(game_id, user_id)`, and the way back in is to reactivate that
     * seat rather than to create a second one. Offering the game here would produce a validation
     * error instead of a seat.
     *
     * Archived games are left out as well. An archived game drops out of the lists that assume a game
     * is still in play, and `AuthenticateAgent` refuses a token for one anyway, so offering it would
     * be offering a seat that cannot act.
     *
     * @return array<int, array{id: int, name: string, short_name: string}>
     */
    private function assignableGames(User $agent): array
    {
        return Game::query()
            ->unarchived()
            ->whereNotIn(
                'id',
                GameSeat::query()->select('game_id')->where('user_id', $agent->getKey()),
            )
            ->orderBy('name')
            ->get()
            ->map(fn (Game $game): array => [
                'id' => $game->id,
                'name' => $game->name,
                'short_name' => $game->short_name,
            ])
            ->values()
            ->all();
    }
}
