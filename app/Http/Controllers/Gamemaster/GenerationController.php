<?php

namespace App\Http\Controllers\Gamemaster;

use App\Actions\Generation\AcceptGeneration;
use App\Actions\Generation\RestartGeneration;
use App\Actions\Generation\RunGeneration;
use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Enums\GenerationStageState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamemaster\GenerationRunRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Building a game's world: the generators a gamemaster runs while the game is in setup.
 *
 * Three endpoints serve every stage — generate, accept, start over — with the stage arriving as a
 * route parameter bound to `App\Enums\GenerationStage`. That is deliberate: the stages differ in what
 * they produce, not in how they are driven, so the workflow is written once and adding the planets
 * stage adds no routes, no controller methods and no screen wiring.
 *
 * Access is the gamemaster gate and nothing else — an active `GameRole::Gamemaster` seat at the game
 * in the URL, decided by `EnsureUserIsGamemaster`, exactly as for the rest of this area. An
 * administrator holding no seat is refused here and has the read-only summary on their own screen.
 *
 * ## Every refusal in here is a 403, and that is the line
 *
 * Whether a stage can run is a question about the *game*: is it still in setup, has the stage before
 * it been accepted, is there something to accept. None of those has a field a message could hang off,
 * and no value the gamemaster could type would make them allowed — so they abort rather than fail
 * validation, and the screen simply does not render controls it knows the server will refuse.
 *
 * The one thing that *is* a validation message lives in `GenerationRunRequest`: the seed, which is a
 * field, and which has to differ from the one already on a pending run.
 */
class GenerationController extends Controller
{
    public function __construct(
        private readonly RunGeneration $runGeneration,
        private readonly AcceptGeneration $acceptGeneration,
        private readonly RestartGeneration $restartGeneration,
    ) {}

    /**
     * Run a stage's generator, replacing whatever pending attempt it had.
     *
     * Allowed from `Ready` — the stage has never been run — and from `Review`, which is the regenerate
     * button. It is refused once the stage is `Accepted`: the stages build on each other, so quietly
     * redrawing a cluster that stelliums already stand on would leave a game describing a world that
     * never existed. Starting over is the way past that, and it says so.
     */
    public function store(GenerationRunRequest $request, Game $game, GenerationStage $stage): RedirectResponse
    {
        $this->authorizeStage($game, $stage, [GenerationStageState::Ready, GenerationStageState::Review]);

        $run = $this->runGeneration->handle(
            $game,
            $stage,
            (int) $request->validated('seed'),
            $request->boolean('traveler'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':stage generated from seed :seed. Review it, then accept or try another seed.', [
                'stage' => $stage->label(),
                'seed' => $run->seed,
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Accept what a stage generated, which unlocks the stage after it.
     */
    public function accept(Game $game, GenerationStage $stage): RedirectResponse
    {
        $this->authorizeStage($game, $stage, [GenerationStageState::Review]);

        $this->acceptGeneration->handle($game, $stage);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':stage accepted.', ['stage' => $stage->label()]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Throw the whole generated world away and start from an empty cluster.
     *
     * The only way past an accepted stage, and all-or-nothing on purpose — see `RestartGeneration` for
     * why there is no per-stage rewind. Refused when there is nothing to throw away, so the control
     * cannot be pressed twice to no effect.
     */
    public function restart(Game $game): RedirectResponse
    {
        abort_unless($game->status === GameStatus::Setup, 403);

        $game->load('generationRuns');

        abort_unless($game->hasGenerationRuns(), 403);

        $this->restartGeneration->handle($game);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Generation was started over. Nothing has been generated for :name yet.', [
                'name' => $game->name,
            ]),
        ]);

        return to_route('gamemaster.games.show', ['game' => $game]);
    }

    /**
     * Refuse anything but a stage standing in one of the states this action is for.
     *
     * The status check is first and separate: generation only ever happens while a game is in setup,
     * whatever state its stages are in.
     *
     * @param  array<int, GenerationStageState>  $allowed
     */
    private function authorizeStage(Game $game, GenerationStage $stage, array $allowed): void
    {
        abort_unless($game->status === GameStatus::Setup, 403);

        $game->load('generationRuns');

        abort_unless(in_array($game->generationStateFor($stage), $allowed, true), 403);
    }
}
