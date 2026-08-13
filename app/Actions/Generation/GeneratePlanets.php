<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\HomeTemplate;
use App\Generation\PlanetGenerator;
use App\Models\GenerationRun;
use App\Models\Planet;
use App\Models\Star;

/**
 * Writes the planets a planets run produced.
 *
 * ## The last stage, because two others decide what it writes
 *
 * A star somebody begins at does not get a drawn system. Its planets come from the game's accepted
 * **home template** — the same count, types and habitability for every player — and only their
 * deposits are drawn, so homes look identical and are worth different amounts to work. The home world
 * itself is settled completely, deposits included, and is overwritten here after the generator has
 * drawn over it.
 *
 * That is why this runs last: it needs the template to copy and the home stellia to know *which*
 * systems to copy it into. It also means a given seed no longer produces the planets it produced when
 * this stage ran third — fewer systems are drawn from scratch and the stream is spent differently.
 * The break was the point of the reordering.
 *
 * ## Pairing
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
        $template = $this->templateOf($run);
        $homes = $this->homeStarIndexes($run, $stars);

        $plan = $this->generator->generate(
            $run->seed,
            count($stars),
            array_fill_keys($homes, $template->planets),
        );

        $homeOrbit = $template->homeOrdinal() - 1;
        $homeWorld = $template->home();

        $now = now();

        $rows = [];

        foreach ($stars as $index => $star) {
            $isHome = in_array($index, $homes, true);

            foreach ($plan->systems[$index]->planets as $orbit => $planet) {
                /*
                 * The one planet the template settles completely. The generator drew deposits for it
                 * along with everything else — it does not know which orbit is anybody's home — and
                 * this is where they are replaced, so that every player's home world is identical down
                 * to the last column.
                 */
                $fixed = $isHome && $orbit === $homeOrbit;

                $rows[] = [
                    'star_id' => $star->id,
                    'generation_run_id' => $run->id,
                    /* Position in the system is the orbit; the generator never named one. */
                    'ordinal' => $orbit + 1,
                    'type' => $planet->type->value,
                    'habitability' => $planet->habitability,
                    'fuel' => $fixed ? $homeWorld->fuel : $planet->fuel,
                    'metals' => $fixed ? $homeWorld->metals : $planet->metals,
                    'minerals' => $fixed ? $homeWorld->minerals : $planet->minerals,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            Planet::query()->insert($chunk);
        }

        return [
            ...$plan->summary(),
            'homes' => count($homes),
            'home_planets' => count($template->planets),
        ];
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
     * The stellium is set back onto each star on the way past. It is the record already in hand, and
     * `homeStarIndexes()` needs `location_id` off it — without this the inverse relation is not loaded
     * and reading it would lazily fetch a stellium per star, which is 141 queries for something this
     * loop is holding.
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
                $star->setRelation('stellium', $stellium);

                $stars[] = $star;
            }
        }

        return $stars;
    }

    /**
     * Read the home template this run's game accepted.
     *
     * Unconditional, because the stage cannot run until the template stage has been accepted — that is
     * `Game::generationStateFor()`'s doing, and the controller refuses anything else with a 403. A
     * fallback here would be a second, weaker copy of that rule, and the shape it would have to invent
     * is exactly the thing the template exists to stop being invented.
     */
    private function templateOf(GenerationRun $run): HomeTemplate
    {
        $template = $run->game->generationRunFor(GenerationStage::HomeStelliaTemplate)?->template;

        return HomeTemplate::fromArray($template ?? []);
    }

    /**
     * Find which stars in the canonical order somebody begins at.
     *
     * A home stands at a single-star system — `GenerateHomeStellia` draws only from those — so each
     * home location contributes exactly one star, and the index of that star in the flattened order is
     * all the generator needs to be told.
     *
     * Matching on `stellium->location_id` rather than re-walking the locations keeps this to the
     * collection already loaded by `starsOf()`'s eager load, and the home locations come from the
     * accepted arrangement rather than from any property of the system itself: being somebody's home
     * is a fact about the roster, not about the stars.
     *
     * @param  list<Star>  $stars
     * @return list<int>
     */
    private function homeStarIndexes(GenerationRun $run, array $stars): array
    {
        $homeLocations = $run->game->generationRunFor(GenerationStage::HomeStellia)
            ?->homeStelliums()
            ->pluck('location_id')
            ->all() ?? [];

        $indexes = [];

        foreach ($stars as $index => $star) {
            if (in_array($star->stellium->location_id, $homeLocations, true)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }
}
