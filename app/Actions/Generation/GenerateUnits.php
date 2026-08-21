<?php

namespace App\Actions\Generation;

use App\Enums\EntityType;
use App\Enums\GameRole;
use App\Enums\GenerationStage;
use App\Generation\HomeTemplate;
use App\Generation\StartingUnits;
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
 * there in orbit above the same planet. Each is given the kit `App\Generation\StartingUnits`
 * describes, which is identical for everybody.
 *
 * ## The one stage with no generator, because it draws nothing
 *
 * Every other stage pairs a pure generator against a seed. There is no seed in this one's stream at
 * all: what a player is handed on turn one is the same for every player, so there is nothing to draw
 * and `StartingUnits` is a description rather than a generator. The run still records the seed it
 * was given, the way a run records `traveler` on a stage that never reads it — a run stores what it
 * was asked for.
 *
 * That is also why `Gamemaster\GenerationRunRequest` exempts this stage from the "choose a different
 * seed" rule: the premise that rule rests on is that the same seed redraws the same thing, and here
 * *every* seed produces the same thing. What regenerating is actually for is a roster that has
 * changed since the stage ran.
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

    public function __construct(private readonly StartingUnits $startingUnits) {}

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

            foreach ($this->startingUnits->entityTypes() as $type) {
                $entityRows[] = [
                    'game_seat_id' => $seat->id,
                    'planet_id' => $homeWorld->id,
                    'generation_run_id' => $run->id,
                    'type' => $type->value,
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
            foreach ($this->startingUnits->for($entity->type) as $holding) {
                $unitRows[] = [
                    'entity_id' => $entity->id,
                    'type' => $holding->type->value,
                    'inventory' => $holding->inventory->value,
                    'quantity' => $holding->quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($unitRows, self::INSERT_CHUNK) as $chunk) {
            Unit::query()->insert($chunk);
        }

        return $this->summary($seats->count(), $entityRows, $unitRows);
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
     * @param  list<array<string, mixed>>  $entityRows
     * @param  list<array<string, mixed>>  $unitRows
     * @return array<string, mixed>
     */
    private function summary(int $players, array $entityRows, array $unitRows): array
    {
        $ofType = fn (EntityType $type): int => count(array_filter(
            $entityRows,
            fn (array $row): bool => $row['type'] === $type->value,
        ));

        $colonies = $ofType(EntityType::Colony);

        return [
            'players' => $players,
            'colonies' => $colonies,
            'ships' => $ofType(EntityType::Ship),
            'units' => count($unitRows),
            'players_without_a_home' => $players - $colonies,
        ];
    }
}
