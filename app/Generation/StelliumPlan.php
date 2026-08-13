<?php

namespace App\Generation;

/**
 * How many stars each location's stellium gets, from one run of `StelliumGenerator`.
 *
 * A list of star counts in location order: the first entry belongs to the location with ordinal 1. It
 * is a list rather than a map keyed by location id because the generator is pure and has never seen a
 * database row — pairing the plan with the cluster is the persisting action's job.
 */
final readonly class StelliumPlan
{
    /**
     * @param  list<int>  $starCounts  one entry per location, in ordinal order
     */
    public function __construct(public array $starCounts) {}

    /**
     * Get how many stelliums this plan covers.
     */
    public function count(): int
    {
        return count($this->starCounts);
    }

    /**
     * Get how many stars the plan places in total.
     */
    public function starTotal(): int
    {
        return array_sum($this->starCounts);
    }

    /**
     * Get how many stelliums have each number of stars, keyed by that number.
     *
     * Keys run over every size the distribution mentions, including any that came out at zero, so a
     * reader can tell "no quadruples this time" from "quadruples are not a thing".
     *
     * @return array<int, int>
     */
    public function mix(): array
    {
        $mix = array_fill_keys(array_keys(StelliumGenerator::STAR_DISTRIBUTION), 0);

        foreach ($this->starCounts as $stars) {
            $mix[$stars] = ($mix[$stars] ?? 0) + 1;
        }

        return $mix;
    }

    /**
     * Describe this plan for the run that produced it.
     *
     * **The key is the label.** `GenerationStageCard` prints a summary's keys as they are, so `stellia`
     * here is the word that reaches the screen — the Latin plural the game is played in, matching the
     * stage's own label. The table and the model stay `stelliums`; only what a reader sees changes.
     *
     * @return array{stellia: int, stars: int, mix: array<int, int>}
     */
    public function summary(): array
    {
        return [
            'stellia' => $this->count(),
            'stars' => $this->starTotal(),
            'mix' => $this->mix(),
        ];
    }
}
