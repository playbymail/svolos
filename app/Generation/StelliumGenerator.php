<?php

namespace App\Generation;

/**
 * Decides how many stars sit at each location.
 *
 * Pure, like `ClusterGenerator`: a seed and a count go in, a `StelliumPlan` comes out.
 *
 * ## The distribution is a quota, not a probability
 *
 * 70% single, 20% double, 9% triple, 1% quadruple is a **fixed multiset**, not an independent roll per
 * location. Over 100 locations every game therefore has exactly 70 single-star stelliums, 20 doubles,
 * 9 triples and one quadruple; the seed decides only *which* location gets which. This is the deliberate
 * choice over rolling each stellium separately, where the counts would drift game to game and the lone
 * quadruple — the rarest and most interesting thing in the cluster — would simply be missing from
 * roughly a third of all games.
 *
 * The quota is built from the percentages by **largest remainder**, so it keeps summing to the number
 * of locations if either the distribution or `ClusterGenerator::LOCATION_COUNT` ever changes: whole
 * shares first, then the leftovers go to the sizes that were cut hardest. At 100 locations every share
 * is already whole and no remainder is handed out at all, which is why the percentages are also the
 * counts today. Ties are broken toward the *smaller* stellium, deterministically, so the same inputs
 * always give the same quota.
 */
class StelliumGenerator
{
    /**
     * How the stelliums are divided, as star count => percentage of the cluster.
     *
     * The percentages must add up to 100; a unit test asserts they do, because a distribution that adds
     * up to 99 would silently leave the last location's share to the rounding.
     */
    public const array STAR_DISTRIBUTION = [
        1 => 70,
        2 => 20,
        3 => 9,
        4 => 1,
    ];

    /**
     * Decide the star count for each location, in ordinal order.
     */
    public function generate(int $seed, int $locationCount): StelliumPlan
    {
        $counts = [];

        foreach ($this->quotaFor($locationCount) as $stars => $stelliums) {
            $counts = [...$counts, ...array_fill(0, $stelliums, $stars)];
        }

        /* The one draw in this generator: which location gets which of the counts above. */
        return new StelliumPlan(array_values(SeededRandomizer::for($seed)->shuffleArray($counts)));
    }

    /**
     * Work out how many stelliums of each size a cluster of this size gets.
     *
     * Largest remainder: everybody takes their whole share, then whatever is left over is handed out
     * one at a time to the sizes with the biggest fractional part. The result always sums to
     * `$locationCount` exactly, which a plain `round()` per size would not.
     *
     * @return array<int, int> star count => how many stelliums have it
     */
    private function quotaFor(int $locationCount): array
    {
        $quota = [];
        $remainders = [];

        foreach (self::STAR_DISTRIBUTION as $stars => $percentage) {
            $share = $locationCount * $percentage / 100;
            $quota[$stars] = (int) floor($share);
            $remainders[$stars] = $share - $quota[$stars];
        }

        /* Biggest fractional part first; a tie goes to the smaller stellium so the order is fixed. */
        uksort($remainders, function (int $left, int $right) use ($remainders): int {
            return $remainders[$right] <=> $remainders[$left] ?: $left <=> $right;
        });

        $leftover = $locationCount - array_sum($quota);

        foreach (array_keys($remainders) as $stars) {
            if ($leftover <= 0) {
                break;
            }

            $quota[$stars]++;
            $leftover--;
        }

        return $quota;
    }
}
