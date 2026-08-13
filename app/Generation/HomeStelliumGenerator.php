<?php

namespace App\Generation;

use Random\Randomizer;

/**
 * Choosing where each player's faction begins.
 *
 * The last stage of building a world, and the only one whose input is the *roster* rather than the
 * stage before it: one home per active player, drawn from the cluster's single-star systems and
 * spread so nobody starts within reach of anybody else.
 *
 * ## The separation is counted one of two ways, and the gamemaster chooses which
 *
 * `$minimumSeparation` is a bare number until `$inHexes` says what it counts:
 *
 * - **Euclidean, the default** — the straight line through all three dimensions, which is the measure
 *   `ClusterGenerator::MINIMUM_SEPARATION` also uses. Distance through space.
 * - **Hexes** — steps on the plane the map draws, `Coordinates::hexDistanceTo()`, which ignores height
 *   entirely. Reach on the map.
 *
 * These are different questions rather than two scales of one, which is why it is a choice and not a
 * conversion. Two systems sharing a column are the **same hex** however far apart they are vertically
 * — up to thirty units — so they are zero apart by one measure and well clear by the other. A game
 * where what matters is how far a fleet must travel wants the first; one where what matters is how
 * much of the map lies between two players wants the second.
 *
 * **The Euclidean comparison is squared on both sides**, exactly as `ClusterGenerator` does it: the
 * coordinates are integers, so squared distances are integers too and "at least 5" means exactly that
 * at the boundary rather than depending on how `sqrt()` rounds.
 *
 * ## Randomised greedy with restarts, not back-tracking
 *
 * Place a home, drop every candidate now too close, repeat. When that paints itself into a corner —
 * and it does, because a home excludes the 61 hexes within 4 of it out of a footprint of ~709 — throw
 * the whole arrangement away and start again from a fresh draw. Back-tracking would be the clever
 * alternative and is not worth it: it needs a second copy of the placement order to unwind and a rule
 * for which choice to revisit, where a restart is a few dozen draws against a candidate list that is
 * never longer than the 70-odd single-star systems in a cluster.
 *
 * **Every draw goes through `SeededRandomizer`.** `shuffle()`, `Arr::random()` and friends are
 * forbidden here as everywhere in this namespace, and `GeneratorPurityTest` reads this file to say so:
 * a shuffled arrangement satisfies every constraint above and is simply a *different* one next time,
 * which no behavioural test can see and which would quietly stop every stored run meaning anything.
 *
 * ## Failure is a real outcome here, unlike the other generators
 *
 * `ClusterGenerator`'s `GenerationFailed` guards a future change of its constants; this one's fires in
 * ordinary use, because a gamemaster can ask for eight homes fifteen hexes apart and no arrangement
 * exists. That is why the failure names the two dials — `Gamemaster\GenerationController` turns it into
 * a message on the separation field rather than a 500, since the number posted is what has to change.
 */
class HomeStelliumGenerator
{
    /**
     * How far apart two home stellia stand, unless the gamemaster says otherwise.
     *
     * A default rather than a constant rule: it is an input on the run, beside the seed, so a game
     * records the separation it actually got. Five is comfortable for the four to six players a game
     * usually has and starts to bind above that, which is exactly when the field earns its place.
     *
     * The **units** are the other half of it, and they are not fixed either — see `$inHexes` on
     * `generate()`. Five is a workable floor read either way, which is why one default serves both.
     */
    public const int DEFAULT_MINIMUM_SEPARATION = 5;

    /**
     * How many arrangements may be attempted before the generator gives up.
     *
     * Each attempt is at most a few dozen draws, so this is cheap even when it runs out — and it does
     * run out, for a separation the cluster cannot satisfy. A generator must fail loudly rather than
     * spin: the alternative is a request that never returns and a gamemaster who cannot tell a hung
     * generator from a slow one.
     */
    public const int MAXIMUM_ATTEMPTS = 1_000;

    /**
     * Choose a home for every player, from a seed.
     *
     * The candidates arrive as plain coordinates in cluster order and the result is **indices into
     * that list**, so this never sees a row and the caller keeps the job of turning positions back
     * into locations — the same division `StelliumGenerator` and `PlanetGenerator` work under.
     *
     * The returned indices are in placement order, which is stable for a given seed: the first player
     * on the roster gets the first one drawn. That, and nothing else, is what pairs a player to a
     * place — there is no second decision hiding in the caller.
     *
     * @param  array<int, Coordinates>  $candidates  the systems a home may stand at, in cluster order
     * @param  int  $homes  how many to place, which is the number of players
     * @param  int  $minimumSeparation  how far apart two homes must stand, at the closest
     * @param  bool  $inHexes  whether that is counted in hexes rather than as a straight-line distance
     * @return array<int, int> indices into `$candidates`, one per home, in placement order
     *
     * @throws GenerationFailed if no arrangement is found within `MAXIMUM_ATTEMPTS`
     */
    public function generate(
        int $seed,
        array $candidates,
        int $homes,
        int $minimumSeparation,
        bool $inHexes = false,
    ): array {
        /*
         * Nothing to place, nothing to draw. A game with no players is an ordinary state — the stage
         * still runs and is still accepted, it simply produces an empty arrangement — and returning
         * before the randomizer is built keeps that from consuming a draw that would shift the stream
         * for a game that *does* have players.
         */
        if ($homes < 1) {
            return [];
        }

        $randomizer = SeededRandomizer::for($seed);
        $candidates = array_values($candidates);

        for ($attempt = 1; $attempt <= self::MAXIMUM_ATTEMPTS; $attempt++) {
            $placed = $this->attemptArrangement($randomizer, $candidates, $homes, $minimumSeparation, $inHexes);

            if ($placed !== null) {
                return $placed;
            }
        }

        throw GenerationFailed::homesUnplaceable($homes, $minimumSeparation, $inHexes, count($candidates));
    }

    /**
     * Determine whether two systems stand far enough apart to both be homes.
     *
     * The one place the two measures differ, kept to a single method so the choice cannot be made
     * twice and differently — `GenerateHomeStellia` measures the realised separation through
     * `separationBetween()` below for exactly the same reason.
     */
    public function isFarEnough(Coordinates $from, Coordinates $to, int $minimumSeparation, bool $inHexes): bool
    {
        if ($inHexes) {
            return $from->hexDistanceTo($to) >= $minimumSeparation;
        }

        /* Squared on both sides, so the boundary is decided in integers rather than by `sqrt()`. */
        return $from->squaredDistanceTo($to) >= $minimumSeparation ** 2;
    }

    /**
     * Measure how far apart two systems are, in whichever unit the run was generated under.
     *
     * A hex count is exact; a Euclidean distance is rounded, because it is a *reading* rather than a
     * comparison — nothing is decided from it, and the two decimal places are what the summary shows.
     */
    public function separationBetween(Coordinates $from, Coordinates $to, bool $inHexes): int|float
    {
        return $inHexes
            ? $from->hexDistanceTo($to)
            : round(sqrt($from->squaredDistanceTo($to)), 2);
    }

    /**
     * Try once to seat every home, returning null the moment the arrangement runs out of room.
     *
     * `$legal` starts as every candidate index and shrinks as homes are placed, so the draw is always
     * over the systems that are still allowed rather than over the whole cluster with a rejection
     * loop. That matters here in a way it does not for `ClusterGenerator`: the cluster rejects a
     * fraction of a percent of its candidates, while a late home can have narrowed the field to a
     * handful, and re-drawing blindly against 70 systems to find one of three would spin.
     *
     * @param  array<int, Coordinates>  $candidates
     * @return array<int, int>|null the placement, or null if this attempt ran out of legal systems
     */
    private function attemptArrangement(
        Randomizer $randomizer,
        array $candidates,
        int $homes,
        int $minimumSeparation,
        bool $inHexes,
    ): ?array {
        $legal = array_keys($candidates);
        $placed = [];

        while (count($placed) < $homes) {
            if ($legal === []) {
                return null;
            }

            $legal = array_values($legal);
            $chosen = $legal[$randomizer->getInt(0, count($legal) - 1)];
            $placed[] = $chosen;

            $legal = array_filter(
                $legal,
                fn (int $index): bool => $this->isFarEnough(
                    $candidates[$index],
                    $candidates[$chosen],
                    $minimumSeparation,
                    $inHexes,
                ),
            );
        }

        return $placed;
    }
}
