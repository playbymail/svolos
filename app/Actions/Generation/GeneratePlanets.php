<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\PlanetGenerator;
use App\Models\GenerationRun;
use App\Models\Planet;
use App\Models\Star;

/**
 * Writes the planets a planets run produced.
 *
 * The plan is a list of systems in star order, so it is paired with the game's stars read in the same
 * order. That order is two levels deep now, and both levels already carry it: `Game::locations()`
 * orders by ordinal and `Stellium::stars()` orders by ordinal, so flattening the eager load *is* the
 * canonical sequence — locations by ordinal, then stars by ordinal within each. Pairing by position
 * rather than by id is what keeps the generator pure: it has never seen a row, and it is told only how
 * many stars there are.
 *
 * Because the count handed to the generator is derived from the very collection the plan is paired
 * back against, the two cannot disagree, and there is no defensive fallback for a missing system —
 * one would only ever hide a mistake by writing a star somebody else's planets.
 *
 * **Not a join.** Reaching the stars through `locations` costs three queries and 241 models, against a
 * join over `stars`, `stelliums` and `locations` which needs every column qualified: all three tables
 * have an `ordinal`, so an unqualified `orderBy` is an ambiguous-column error, and an unaliased select
 * collides on `id` and overwrites the model key on hydration. There is also no `stars.generation_run_id`
 * to scope by — going through the game's locations is the only correct scoping, and it is safe because
 * superseding a stelliums run deletes the stelliums and cascades the stars.
 */
class GeneratePlanets implements StageGeneration
{
    /**
     * How many planets go into one insert statement.
     *
     * SQLite's `MAX_VARIABLE_NUMBER` is 32,766 and a planet binds ten columns, so one statement holds
     * 3,276 rows. A cluster generates about 775 planets and could at the very most produce 1,410
     * (141 stars × 10), which fits — but only while those constants stay where they are, and Laravel
     * does not chunk inserts for you. Two statements today, inside the transaction `RunGeneration`
     * already owns, is the cheap way not to have to re-derive that arithmetic later.
     */
    public const int INSERT_CHUNK = 500;

    public function __construct(private readonly PlanetGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::Planets;
    }

    /**
     * Give every star of this run's game its planets.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $stars = $this->starsOf($run);

        $plan = $this->generator->generate($run->seed, count($stars));

        $now = now();

        $rows = [];

        foreach ($stars as $index => $star) {
            foreach ($plan->systems[$index]->planets as $orbit => $planet) {
                $rows[] = [
                    'star_id' => $star->id,
                    'generation_run_id' => $run->id,
                    /* Position in the system is the orbit; the generator never named one. */
                    'ordinal' => $orbit + 1,
                    'type' => $planet->type->value,
                    'habitability' => $planet->habitability,
                    'fuel' => $planet->fuel,
                    'metals' => $planet->metals,
                    'minerals' => $planet->minerals,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            Planet::query()->insert($chunk);
        }

        return $plan->summary();
    }

    /**
     * Throw away the planets a superseded run placed.
     */
    public function discard(GenerationRun $run): void
    {
        $run->planets()->delete();
    }

    /**
     * Read every star of a run's game, in the one order the plan is paired against.
     *
     * Locations by ordinal, then stars by ordinal within each — both orderings come from the relations
     * themselves, so this loop only flattens them.
     *
     * @return list<Star>
     */
    private function starsOf(GenerationRun $run): array
    {
        $stars = [];

        foreach ($run->game->locations()->with('stellium.stars')->get() as $location) {
            $stellium = $location->stellium;

            /*
             * Unreachable while the stage is locked until the stelliums are accepted, and skipped
             * rather than assumed away because the relation really is nullable: a location has no
             * stellium between the two stages.
             */
            if ($stellium === null) {
                continue;
            }

            foreach ($stellium->stars as $star) {
                $stars[] = $star;
            }
        }

        return $stars;
    }
}
