<?php

namespace App\Actions\Generation;

use App\Enums\EntityType;
use App\Enums\GameRole;
use App\Enums\GenerationStage;
use App\Generation\HomeTemplate;
use App\Generation\Kit;
use App\Generation\KitGenerator;
use App\Models\Entity;
use App\Models\Game;
use App\Models\GameSeat;
use App\Models\GenerationRun;
use App\Models\Planet;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes the position every player opens the game in.
 *
 * Two entities a player: a **colony** on their home world, and the **ship** that carried its people
 * there in orbit above the same planet. Each is given the same kit, and every player in the game is
 * given that same kit — see `App\Generation\Kit`.
 *
 * ## One kit for the game, and every player in it gets that kit
 *
 * The kit is settled **once** and handed to every seat unchanged, which is the fairness rule this
 * stage has always had: what you are handed on turn one must not depend on which seat you took. What
 * *has* changed is that the kit is no longer the same in every game. It arrives one of three ways —
 * drawn from the run's seed by `KitGenerator`, chosen from the gamemaster's library, or uploaded as
 * a document — and by the time it reaches `handle()` all three look identical, because
 * `Gamemaster\GenerationController` has already turned the last two into `$run->kit`.
 *
 * So a null `$run->kit` means "nothing was chosen, draw one", exactly as a null `$run->template`
 * does one stage earlier, and `App\Generation\StartingUnits` is the baseline `KitGenerator` draws
 * around rather than the kit itself. See `App\Generation\Kit` for why varying a kit **between**
 * games leaves the per-player rule untouched.
 *
 * Parsing happens at the edge rather than here for the reason `GenerateHomeTemplate` gives: a
 * malformed document is a message about a *posted file*, and the run should not exist at all if the
 * file was never usable — where a drawn kit cannot fail and needs the run's seed, which only exists
 * once the run does.
 *
 * ## Last, because it needs a world to stand on
 *
 * A home stellium says which *system* somebody begins at; it does not say which world. The home world
 * is the planet at the home template's `home_ordinal` around that system's single star, and it does
 * not exist until the planets stage has written it. So this stage reads three earlier ones — the
 * template for the ordinal, the home stellia for the systems, the planets for the worlds themselves —
 * and could not sit anywhere but at the end.
 */
class GenerateUnits implements StageGeneration
{
    /**
     * How many rows go into one insert statement.
     *
     * Far below anything SQLite's `MAX_VARIABLE_NUMBER` would object to at any realistic roster — the
     * kit is seventeen holdings a player — and here for the reason `GeneratePlanets` has one: nothing
     * bounds the roster in the schema, and chunking is three lines against arithmetic somebody would
     * otherwise have to redo the day a game is run with a hundred seats.
     */
    public const int INSERT_CHUNK = 500;

    public function __construct(private readonly KitGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::Assets;
    }

    /**
     * Give every active player their opening position.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $seats = $this->playerSeats($run->game);
        $homeWorlds = $this->homeWorldsBySeat($run->game);

        /*
         * Settled once, here, and then handed to every seat below without being consulted again. The
         * assignment persists because `RunGeneration` saves the run a second time to store the
         * summary this returns — the same seam `GenerateHomeTemplate` relies on.
         */
        $kit = $run->kit === null
            ? $this->generator->generate($run->seed)
            : Kit::fromArray($run->kit);

        $run->kit = $kit->toArray();

        $now = now();

        $entityRows = [];

        foreach ($seats as $seat) {
            $homeWorld = $homeWorlds[$seat->id] ?? null;

            /*
             * A player seated after the home stellia stage was accepted has nowhere to begin. It is an
             * ordinary state rather than a failure — `Game::playersWithoutHomeStellium()` already
             * reports it and `GameValidationRules::gameStatusRules()` already refuses to let the game
             * start while it holds — and the remedy is regenerating the homes, not a message on any
             * field of this form. So they are skipped, and counted in the summary so that the review
             * card says it out loud rather than quietly placing fewer colonies than there are players.
             */
            if ($homeWorld === null) {
                continue;
            }

            foreach ($kit->entities as $kitEntity) {
                $entityRows[] = [
                    'game_seat_id' => $seat->id,
                    'planet_id' => $homeWorld->id,
                    'generation_run_id' => $run->id,
                    'type' => $kitEntity->type->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($entityRows, self::INSERT_CHUNK) as $chunk) {
            Entity::query()->insert($chunk);
        }

        $unitRows = [];

        foreach ($this->placedEntities($run) as $entity) {
            foreach ($kit->for($entity->type) as $holding) {
                $unitRows[] = [
                    'entity_id' => $entity->id,
                    'type' => $holding->type->value,
                    'inventory' => $holding->inventory->value,
                    'technology_level' => $holding->technologyLevel,
                    'quantity' => $holding->quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($unitRows, self::INSERT_CHUNK) as $chunk) {
            Unit::query()->insert($chunk);
        }

        return $this->summary($seats->count(), $entityRows, $unitRows, $kit);
    }

    /**
     * Throw away the opening positions a superseded run placed.
     *
     * The units go with them through the database's cascade rather than through a model event: this
     * is a mass delete, and a mass delete fires none.
     */
    public function discard(GenerationRun $run): void
    {
        $run->entities()->delete();
    }

    /**
     * Get the seats that begin the game with something, in a stable order.
     *
     * Active players only, and ordered by `id` — the same rule and the same order as
     * `GenerateHomeStellia::playerSeats()`, and deliberately the same: a seat that was given a home
     * has to be a seat that is given something to put on it, and two different answers to "who is
     * playing" would eventually place a colony at nobody's system.
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
     * Find every player's home world, keyed by the seat that begins there.
     *
     * The walk is: the accepted arrangement's homes → the location each stands at → its stellium →
     * that stellium's single star → the planet whose orbit is the template's `home_ordinal`. Three
     * accepted stages are being read at once, which is why this stage is last.
     *
     * Eager-loaded the whole way down, so the number of queries is fixed rather than proportional to
     * the roster.
     *
     * A seat is **absent** from the result rather than mapped to null, and there are two ways that
     * happens. One is ordinary and reachable: a player seated after the homes were arranged has no
     * home stellium at all. The other — a home system with no planet in the home orbit — cannot happen
     * while the stage is locked until the planets are accepted, because `GeneratePlanets` copies the
     * template into exactly these systems. Neither gets a fallback: inventing a world for somebody to
     * start on would be the one mistake worth failing over.
     *
     * @return array<int, Planet>
     */
    private function homeWorldsBySeat(Game $game): array
    {
        $arrangement = $game->generationRunFor(GenerationStage::HomeStellia);

        if ($arrangement === null) {
            return [];
        }

        $homeOrdinal = $this->homeOrdinalOf($game);

        $homes = $arrangement->homeStelliums()
            ->with('location.stellium.stars.planets')
            ->get();

        $worlds = [];

        foreach ($homes as $home) {
            $star = $home->location->stellium?->stars->first();

            $planet = $star?->planets->firstWhere('ordinal', $homeOrdinal);

            if ($planet instanceof Planet) {
                $worlds[$home->game_seat_id] = $planet;
            }
        }

        return $worlds;
    }

    /**
     * Read which orbit the home world sits in, from the template this game accepted.
     *
     * Unconditional for the reason `GeneratePlanets::templateOf()` is: the stage cannot run until the
     * template has been accepted, and a fallback here would be a second, weaker copy of that rule.
     */
    private function homeOrdinalOf(Game $game): int
    {
        $template = $game->generationRunFor(GenerationStage::HomeStelliaTemplate)?->template;

        return HomeTemplate::fromArray($template ?? [])->homeOrdinal();
    }

    /**
     * Read back the entities this run just wrote, so their units can point at them.
     *
     * The ids are not known until after the insert — the rows are written in bulk, so nothing hands
     * them back — and this is the same read-back `GenerateStelliums` does between its two inserts.
     * Scoped to the run, which is exactly the set that was just written.
     *
     * @return Collection<int, Entity>
     */
    private function placedEntities(GenerationRun $run): Collection
    {
        return $run->entities()->get(['id', 'type']);
    }

    /**
     * Describe what was placed, for the card that reviews it.
     *
     * `players_without_a_home` is the number the reader needs and the one a count of colonies would
     * hide: a game with four players and three colonies has a fourth player who was seated late, and
     * the card should say so rather than leaving somebody to subtract.
     *
     * The kit's own summary is folded in under `kit_` keys so the card says which of the three ways
     * this one arrived. `GenerationStageCard` drops null values rather than printing them, so a drawn
     * kit shows its seed and no file and an uploaded one shows the reverse, with nothing branching.
     *
     * @param  list<array<string, mixed>>  $entityRows
     * @param  list<array<string, mixed>>  $unitRows
     * @return array<string, mixed>
     */
    private function summary(int $players, array $entityRows, array $unitRows, Kit $kit): array
    {
        $ofType = fn (EntityType $type): int => count(array_filter(
            $entityRows,
            fn (array $row): bool => $row['type'] === $type->value,
        ));

        $colonies = $ofType(EntityType::OpenAirColony);

        return [
            'players' => $players,
            'colonies' => $colonies,
            'ships' => $ofType(EntityType::Ship),
            'units' => count($unitRows),
            'players_without_a_home' => $players - $colonies,
            'kit_file' => $kit->file,
            'kit_seed' => $kit->seed,
            'kit_holdings' => $kit->holdingCount(),
        ];
    }
}
