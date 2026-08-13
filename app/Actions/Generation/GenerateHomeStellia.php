<?php

namespace App\Actions\Generation;

use App\Enums\GameRole;
use App\Enums\GenerationStage;
use App\Generation\Coordinates;
use App\Generation\HomeStelliumGenerator;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\HomeStellium;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes the home each player's faction begins at.
 *
 * The only stage whose input is the game's **roster** rather than the stage before it. Everything else
 * about it is the ordinary shape: the pure generator picks positions in a candidate list, and this
 * turns positions back into rows.
 *
 * ## The stream is seeded with the seed *and the attempt*, and only here
 *
 * Every other stage seeds its generator with `$run->seed` alone, because a stored seed has to keep
 * meaning one fixed world — feed it back through the generator and the same cluster comes out. This
 * stage folds `$run->attempt` in, so pressing Generate again **without touching the seed** produces a
 * different arrangement of the same world. That is the whole interaction: a gamemaster is not choosing
 * a number, they are asking for another shuffle of where people start.
 *
 * Two consequences that are easy to undo by accident:
 *
 * - `Gamemaster\GenerationRunRequest` exempts this stage from the "choose a seed other than the one
 *   that produced this" rule. That rule exists because regenerating with the same seed *would redraw
 *   the same thing*, which stops being true the moment the attempt is in the stream — and leaving it
 *   on would forbid the one gesture this stage exists for.
 * - The run is still exactly reproducible, because `attempt` is stored on it. `seed + attempt` is not
 *   a way of being random, it is a way of indexing the arrangements of one seed.
 *
 * The modulo keeps the result inside Mt19937's 32-bit range for a seed near `Game::SEED_MAX`.
 *
 * ## Candidates are single-star systems, and the constraint lives in the draw
 *
 * A home stands at one star — a player beginning in a quadruple would start with four times the
 * planets of everybody else — so this hands the generator only the locations whose stellium has
 * exactly one. It is a filter on what may be *drawn* rather than a rule checked afterwards, which is
 * why the generator can be honest about failing: it is told everything it is allowed to use.
 */
class GenerateHomeStellia implements StageGeneration
{
    public function __construct(private readonly HomeStelliumGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::HomeStellia;
    }

    /**
     * Give every active player of this run's game a home.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $seats = $this->playerSeats($run->game);
        $candidates = $this->singleStarLocations($run->game);

        $chosen = $this->generator->generate(
            ($run->seed + $run->attempt) % (Game::SEED_MAX + 1),
            array_map(fn (Location $location): Coordinates => $location->coordinates(), $candidates),
            $seats->count(),
            $run->minimum_separation,
            $run->separation_in_hexes,
        );

        $now = now();

        HomeStellium::query()->insert(
            $seats->values()->map(fn (GameSeat $seat, int $index): array => [
                'generation_run_id' => $run->id,
                'game_seat_id' => $seat->id,
                /* Placement order pairs a player to a place, and nothing else does. */
                'location_id' => $candidates[$chosen[$index]]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return $this->summary($run, $candidates, $chosen);
    }

    /**
     * Throw away the arrangement a superseded run chose.
     *
     * The seats are untouched: a home stellium is a fact about a generated world, and losing one is not
     * losing your place at the game.
     */
    public function discard(GenerationRun $run): void
    {
        $run->homeStelliums()->delete();
    }

    /**
     * Get the seats that get a home, in a stable order.
     *
     * **Active players only.** A gamemaster runs the game rather than playing it, and a retired seat is
     * somebody who has left — giving either a starting system would put a faction on the map that
     * nobody is playing.
     *
     * Ordered by `id`, which is the order the seats were added. Any fixed order would do; having one is
     * what makes the run reproducible, since the generator returns its placements in draw order and the
     * pairing is positional.
     *
     * @return Collection<int, GameSeat>
     */
    private function playerSeats(Game $game): Collection
    {
        return $game->activeSeats()
            ->where('role', GameRole::Player)
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the locations a home may stand at: those whose stellium holds exactly one star.
     *
     * One query, counting the stars the same way `PresentsGeneration::presentLocations()` does, and the
     * filtering happens here rather than in SQL because the coordinates of every candidate are needed
     * anyway — the rows are already on their way back.
     *
     * A **list**, re-indexed by `values()`, because the generator returns positions into it and pairs a
     * player to a place by nothing else. A collection with gaps in its keys would pair them wrongly and
     * silently.
     *
     * @return array<int, Location>
     */
    private function singleStarLocations(Game $game): array
    {
        return $game->locations()
            ->with(['stellium' => fn ($query) => $query->withCount('stars')])
            ->get()
            ->filter(fn (Location $location): bool => $location->stellium?->stars_count === 1)
            ->values()
            ->all();
    }

    /**
     * Describe what was placed, for the card that reviews it.
     *
     * `realised_separation` is **measured from the arrangement after the fact**, the way `LocationSet`
     * measures the cluster's own separation rather than echoing what the generator was told: the card
     * then states what the arrangement *is*, and the two numbers can never drift apart. It is usually
     * larger than the minimum asked for, which is the useful part — it says how much room was actually
     * left, and therefore whether the separation could be raised.
     *
     * It is measured **in the unit the run was generated under**, through the generator's own
     * `separationBetween()`. Measuring it one fixed way would print a hex count beside a Euclidean
     * minimum for half of all runs, which reads as an arrangement that broke its own rule.
     *
     * Null below two homes, where the nearest neighbour does not exist. That is not the same as zero.
     *
     * @param  array<int, Location>  $candidates
     * @param  array<int, int>  $chosen
     * @return array<string, mixed>
     */
    private function summary(GenerationRun $run, array $candidates, array $chosen): array
    {
        $placed = array_map(fn (int $index): Location => $candidates[$index], $chosen);

        $closest = null;

        foreach ($placed as $position => $home) {
            foreach (array_slice($placed, $position + 1) as $other) {
                $separation = $this->generator->separationBetween(
                    $home->coordinates(),
                    $other->coordinates(),
                    $run->separation_in_hexes,
                );

                $closest = $closest === null ? $separation : min($closest, $separation);
            }
        }

        return [
            'players' => count($placed),
            'candidates' => count($candidates),
            'minimum_separation' => $run->minimum_separation,
            'realised_separation' => $closest,
        ];
    }
}
