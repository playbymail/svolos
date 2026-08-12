<?php

namespace App\Generation;

use App\Enums\PlanetType;
use Random\Randomizer;

/**
 * Gives every star its planets.
 *
 * Pure, like the two generators before it: a seed and a star count go in, a `PlanetPlan` comes out,
 * and nothing here touches the database, the clock or the container. It is told **how many stars**,
 * not which — nothing about one planet system depends on another, so the shape of the cluster is
 * information this class could not use and deliberately never sees.
 *
 * ## Where a planet sits decides what it is
 *
 * Each star gets `PLANET_DICE` planets, numbered outward from 1. A planet's position in its system is
 * the fraction `(2·ordinal − 1) / (2·count)` — the midpoint of its slice of the run of orbits — and
 * that fraction falls in one of four zones, each with its own weights over the four types. Inner is
 * rocky, the belt is asteroids, outer is gas giants, far is ice.
 *
 * **The zone boundaries are our own solar system's proportions**, in ninths: of nine bodies, orbits
 * one to four are rocky, five is the belt, six and seven are gas giants, eight and nine are ice
 * giants. So `ZONE_BOUNDARIES` is `[4, 5, 7]` out of `SOLAR_SYSTEM_ORBITS`, and a star with exactly
 * nine planets reproduces that arrangement zone for zone. Those boundaries do nearly all the work of
 * matching the solar system's mix; the weight tables only sharpen it.
 *
 * The comparison is **integer**, the way `ClusterGenerator` compares squared distances: a boundary
 * that has to fall one specific way must not be decided in floating point.
 * `(2o − 1)/(2N) < k/9` is `9(2o − 1) < 2kN`, which is what `zoneFor()` computes.
 *
 * ## The zones are not equal, and small systems do not reach all four
 *
 * The belt is one ninth wide, so any star with fewer than nine planets must skip some zone, and which
 * one it skips depends on how many it has. Both of these read like bugs and are not:
 *
 * - **A lone planet lands in the belt** — the midpoint of its system — so a single-planet star is
 *   usually an asteroid field. That is about two stars in a game.
 * - **A three-planet system has no outer zone at all**, so gas giants are rare around those stars.
 *
 * Weighting the zones by how many planets actually land in them across the `PLANET_DICE` distribution
 * gives inner 45.2%, belt 9.7%, outer 22.4%, far 22.7% — not four quarters. The weight tables are
 * tuned against *those* shares, which is why they do not look like the solar system's proportions
 * themselves. `PlanetGeneratorTest` pins both the per-count zone table and the resulting mix.
 *
 * ## A probability here, where the stelliums were a quota
 *
 * `StelliumGenerator` deals a fixed multiset because rolling each stellium independently would let the
 * lone quadruple — the rarest thing in a cluster — go missing altogether. Planets do the opposite, and
 * it is not an oversight: **a quota is incompatible with zoning**. The whole point is that where a
 * planet sits decides what it is, and dealing out predetermined type counts would overrule that. It is
 * safe here where it was not there because there are some 775 planets rather than 100 locations, so
 * the mix's standard deviation is about one percentage point and nothing rare can vanish.
 *
 * ## Two hazards worth knowing before editing a table
 *
 * - **`pick()` walks a weight table in insertion order**, so reordering the keys of a `ZONE_WEIGHTS`
 *   row changes the world a seed produces without changing the odds of anything. The same hazard lives
 *   in `StelliumGenerator::quotaFor()`'s `uksort`.
 * - **The draw schedule is variable.** Type is drawn first, and how many dice follow depends on the
 *   type that came up — five for a rocky world's habitability, none for an asteroid field's. That is
 *   still entirely determined by the seed, but it means retuning *any* weight shifts every draw after
 *   it: a regenerated world differs everywhere, not just where the change applies. `ClusterGenerator`
 *   can keep a fixed schedule and this cannot, because there the draws are three coordinates every
 *   time and here the dice belong to the type.
 */
class PlanetGenerator
{
    /**
     * How many planets a star gets, as `[dice, sides, modifier]`.
     *
     * 3d4 − 2: one to ten, averaging 5.5, with the middle far more likely than either end.
     *
     * @var array{int, int, int}
     */
    public const array PLANET_DICE = [3, 4, -2];

    /**
     * How many orbits the arrangement of zones is expressed in.
     *
     * Nine, because the solar system has nine bodies in the count this distribution follows — four
     * rocky, the belt, two gas giants, two ice giants.
     */
    public const int SOLAR_SYSTEM_ORBITS = 9;

    /**
     * Where one zone ends and the next begins, in orbits out of `SOLAR_SYSTEM_ORBITS`.
     *
     * Read as: rocky through orbit 4, the belt at 5, gas giants through 7, ice beyond. There is one
     * fewer boundary than there are zones — everything past the last one is the outermost zone.
     *
     * @var list<int>
     */
    public const array ZONE_BOUNDARIES = [4, 5, 7];

    /**
     * What each zone is made of, as weights over the types.
     *
     * Indexed in the order the boundaries above describe: 0 inner, 1 belt, 2 outer, 3 far. Every row
     * sums to 100, which a unit test asserts — a row summing to 99 would leave `pick()` able to roll
     * past the end of it.
     *
     * @var list<array<string, int>>
     */
    public const array ZONE_WEIGHTS = [
        [PlanetType::Rocky->value => 84, PlanetType::Asteroids->value => 6, PlanetType::GasGiant->value => 6, PlanetType::Icy->value => 4],
        [PlanetType::Rocky->value => 20, PlanetType::Asteroids->value => 66, PlanetType::GasGiant->value => 8, PlanetType::Icy->value => 6],
        [PlanetType::Rocky->value => 12, PlanetType::Asteroids->value => 4, PlanetType::GasGiant->value => 66, PlanetType::Icy->value => 18],
        [PlanetType::Rocky->value => 6, PlanetType::Asteroids->value => 4, PlanetType::GasGiant->value => 20, PlanetType::Icy->value => 70],
    ];

    /**
     * How habitable each kind of planet is, as `[dice, sides, modifier]`.
     *
     * Rocky spans exactly the 0–25 the attribute is declared over, so the top of the scale belongs to
     * the type that deserves it and a dead rock is still possible. **Asteroids are zero dice**, which
     * is how "an asteroid field is never habitable" is stated as data rather than as a branch in the
     * code: `roll()` with no dice returns the modifier.
     *
     * @var array<string, array{int, int, int}>
     */
    public const array HABITABILITY_DICE = [
        PlanetType::Rocky->value => [5, 6, -5],
        PlanetType::Asteroids->value => [0, 6, 0],
        PlanetType::GasGiant->value => [1, 6, -1],
        PlanetType::Icy->value => [2, 6, -2],
    ];

    /**
     * What each kind of planet holds, as `[dice, sides, modifier]` per deposit.
     *
     * Each type is good for something. Gas giants are the fuel, asteroids the metals and minerals,
     * rocky worlds a little of everything, ice somewhere between.
     *
     * **Asteroids reach 35 where nothing else passes 24, and that is the trade.** They are the one
     * type that can never be lived on — a flat zero above, not a draw — so they are reliably the
     * richest thing to mine, with a floor of 10 rather than a chance of nothing. Habitability and
     * extraction pull against each other on purpose. Raising an asteroid field's habitability off zero
     * and capping its deposits at everything else's ceiling are the same mistake from either end.
     *
     * Every entry's minimum is at or above zero and its maximum at or below 255, which a unit test
     * asserts: the columns are `unsignedTinyInteger`, and SQLite would store a negative silently.
     *
     * @var array<string, array<string, array{int, int, int}>>
     */
    public const array DEPOSIT_DICE = [
        PlanetType::Rocky->value => ['fuel' => [1, 6, -1], 'metals' => [2, 6, 0], 'minerals' => [2, 6, 0]],
        PlanetType::Asteroids->value => ['fuel' => [1, 4, -1], 'metals' => [5, 6, 5], 'minerals' => [5, 6, 5]],
        PlanetType::GasGiant->value => ['fuel' => [3, 8, 0], 'metals' => [1, 4, -1], 'minerals' => [1, 4, -1]],
        PlanetType::Icy->value => ['fuel' => [2, 8, 0], 'metals' => [1, 6, -1], 'minerals' => [2, 6, 0]],
    ];

    /**
     * Give each of a cluster's stars its planets, in the order the stars were counted.
     *
     * @throws GenerationFailed if a zone's weights do not cover the roll made against them
     */
    public function generate(int $seed, int $starCount): PlanetPlan
    {
        $randomizer = SeededRandomizer::for($seed);

        $systems = [];

        for ($star = 0; $star < $starCount; $star++) {
            $count = $this->roll($randomizer, self::PLANET_DICE);

            $planets = [];

            for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
                $planets[] = $this->profile($randomizer, $ordinal, $count);
            }

            $systems[] = new PlanetSystem($planets);
        }

        return new PlanetPlan($systems);
    }

    /**
     * Work out which zone the planet in a given orbit sits in.
     *
     * `(2o − 1)/(2N) < k/9` rearranged into integers as `9(2o − 1) < 2kN`. Returns the index of the
     * first boundary the planet falls short of, or the outermost zone if it clears them all.
     */
    public function zoneFor(int $ordinal, int $count): int
    {
        $position = self::SOLAR_SYSTEM_ORBITS * (2 * $ordinal - 1);

        foreach (self::ZONE_BOUNDARIES as $zone => $boundary) {
            if ($position < 2 * $boundary * $count) {
                return $zone;
            }
        }

        return count(self::ZONE_BOUNDARIES);
    }

    /**
     * Draw one planet: what it is, then what that makes it worth.
     *
     * The locals are deliberate. Argument evaluation order decides the draw order here, and while PHP
     * fixes it left to right, a file where the order of draws *is* the world should not lean on a
     * language detail a reader has to recall.
     *
     * @throws GenerationFailed
     */
    private function profile(Randomizer $randomizer, int $ordinal, int $count): PlanetProfile
    {
        $type = $this->pick($randomizer, self::ZONE_WEIGHTS[$this->zoneFor($ordinal, $count)]);
        $deposits = self::DEPOSIT_DICE[$type->value];

        $habitability = $this->roll($randomizer, self::HABITABILITY_DICE[$type->value]);
        $fuel = $this->roll($randomizer, $deposits['fuel']);
        $metals = $this->roll($randomizer, $deposits['metals']);
        $minerals = $this->roll($randomizer, $deposits['minerals']);

        return new PlanetProfile($type, $habitability, $fuel, $metals, $minerals);
    }

    /**
     * Roll a `[dice, sides, modifier]` expression.
     *
     * No dice is not a special case: the loop simply does not run and the modifier is the answer, which
     * is what makes an asteroid field's habitability a table entry rather than an `if`.
     *
     * @param  array{int, int, int}  $dice
     */
    private function roll(Randomizer $randomizer, array $dice): int
    {
        [$count, $sides, $modifier] = $dice;

        $total = $modifier;

        for ($die = 0; $die < $count; $die++) {
            $total += $randomizer->getInt(1, $sides);
        }

        return $total;
    }

    /**
     * Choose a type from a zone's weights.
     *
     * One draw against the total, then walk the table until the running sum covers it. The throw is
     * unreachable while every row sums to its own total — which it must, since that total *is* what
     * was rolled against — and it is here for the mis-edited table that makes it reachable, on the
     * same principle as `ClusterGenerator::MAXIMUM_ATTEMPTS`: fail loudly rather than return a
     * quietly wrong world.
     *
     * @param  array<string, int>  $weights
     *
     * @throws GenerationFailed
     */
    private function pick(Randomizer $randomizer, array $weights): PlanetType
    {
        $total = array_sum($weights);

        $roll = $randomizer->getInt(1, $total);
        $running = 0;

        foreach ($weights as $type => $weight) {
            $running += $weight;

            if ($roll <= $running) {
                return PlanetType::from($type);
            }
        }

        throw GenerationFailed::weightsExhausted($roll, $total);
    }
}
