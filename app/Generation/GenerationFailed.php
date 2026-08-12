<?php

namespace App\Generation;

use RuntimeException;

/**
 * A generator could not produce what it was asked for.
 *
 * At the values this application ships with, this cannot happen: 100 locations at a minimum separation
 * of 2 use 0.7% of the 14,146 points available, and the measured worst case over two thousand seeds was
 * a few hundred draws against a cap of a hundred thousand. It exists for the change that alters those
 * numbers — many more locations, a wider separation, a smaller radius — where dart throwing stops
 * terminating. **A generator must fail loudly rather than spin**, because the alternative is a request
 * that never returns and a gamemaster who cannot tell a hung generator from a slow one.
 */
class GenerationFailed extends RuntimeException
{
    /**
     * The generator ran out of attempts before it had placed everything it was asked to.
     */
    public static function attemptsExhausted(int $placed, int $wanted, int $attempts): self
    {
        return new self(
            "Placed only {$placed} of {$wanted} locations in {$attempts} attempts. "
            .'The cluster is too crowded for the requested count and separation.'
        );
    }

    /**
     * A weighted choice rolled past the end of the table it was choosing from.
     *
     * Only reachable if a table's weights no longer sum to what was rolled against them, which means
     * somebody edited one. See `PlanetGenerator::pick()`.
     */
    public static function weightsExhausted(int $roll, int $total): self
    {
        return new self(
            "Rolled {$roll} against weights totalling {$total} and fell off the end of the table. "
            .'A weight table has been edited into disagreeing with its own total.'
        );
    }
}
