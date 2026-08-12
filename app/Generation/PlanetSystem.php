<?php

namespace App\Generation;

/**
 * The planets of one star, ordered outward.
 *
 * Position is the orbit: the first entry is the innermost planet, and the ordinal a row eventually
 * gets is its index plus one. Holding the order rather than a map keyed by ordinal is what lets the
 * generator stay ignorant of rows — pairing a system with a real star is the persisting action's job,
 * exactly as it is for `StelliumPlan`.
 */
final readonly class PlanetSystem
{
    /**
     * @param  list<PlanetProfile>  $planets  innermost first
     */
    public function __construct(public array $planets) {}

    /**
     * Get how many planets orbit this star.
     */
    public function count(): int
    {
        return count($this->planets);
    }
}
