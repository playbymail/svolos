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
     * The field a gamemaster would have to change for this to succeed.
     *
     * `Gamemaster\GenerationController` turns this exception into a validation message rather than
     * letting it 500, so the failure has to name a field the form actually has. **Which field is a
     * property of the failure, not of the stage**: the same controller line serves every generator,
     * and a `match` on the stage there would be a second copy of this knowledge sitting further from
     * the thing that knows it.
     *
     * `seed` is the default because that is the only input every stage has, and a generator that fails
     * for a reason nobody has thought of yet is at least telling somebody to try another number.
     */
    public string $field = 'seed';

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
     * No arrangement puts every player's home far enough from every other.
     *
     * **Unlike the two failures beside it, this one is reachable in ordinary use** — a gamemaster can
     * ask for eight homes twelve hexes apart in a cluster that has nowhere to put them, and no seed
     * will change that. So the sentence is written for them rather than for whoever is reading a stack
     * trace: it names the separation, because that is the dial that moves.
     */
    public static function homesUnplaceable(int $homes, int $minimumSeparation, bool $inHexes, int $candidates): self
    {
        /*
         * Said in the unit that was actually asked for, since that is the number on the form. A bare
         * "5 apart" for the Euclidean case matches how the map's own readout words a distance, and
         * the cluster's coordinates have no unit to name.
         */
        $separation = $inHexes ? "{$minimumSeparation} hexes apart" : "{$minimumSeparation} apart";

        $failure = new self(
            "No arrangement puts {$homes} home stellia at least {$separation} "
            ."among the {$candidates} single-star systems in this cluster. "
            .'Try a smaller minimum separation.'
        );

        $failure->field = 'minimum_separation';

        return $failure;
    }

    /**
     * An uploaded home template was not JSON at all.
     *
     * Reachable the moment somebody uploads the wrong file, so the sentence names the likely mistake
     * rather than only the parser's complaint — which on its own ("Syntax error") tells a gamemaster
     * nothing about what to do next.
     */
    public static function templateUnreadable(string $reason): self
    {
        $failure = new self("That file is not readable as JSON ({$reason}). Upload the template document itself.");

        $failure->field = 'template';

        return $failure;
    }

    /**
     * An uploaded home template was JSON, but not a home template.
     *
     * The most reachable failure in the whole application — a gamemaster writing a document by hand
     * will hit it — so `HomeTemplate` composes the sentence naming the planet and the field, and this
     * only carries it to the form. Like `homesUnplaceable()`, the field is the one they can fix.
     */
    public static function templateMalformed(string $problem): self
    {
        $failure = new self($problem);

        $failure->field = 'template';

        return $failure;
    }

    /**
     * An uploaded kit was not JSON at all.
     *
     * The same failure as `templateUnreadable()` one stage further on, and worded the same way for
     * the same reason: "Syntax error" on its own tells a gamemaster nothing about what to do next.
     */
    public static function kitUnreadable(string $reason): self
    {
        $failure = new self("That file is not readable as JSON ({$reason}). Upload the kit document itself.");

        $failure->field = 'kit';

        return $failure;
    }

    /**
     * An uploaded kit was JSON, but not a kit a game could open with.
     *
     * `Kit` composes the sentence naming the entity and the holding, and this only carries it to the
     * form. Some of those sentences come from `UnitHolding`'s and `KitEntity`'s constructors, which
     * throw `InvalidArgumentException` because they are also how the catalogue's own kits are written
     * — `Kit` catches those and rethrows them through here, which is the seam where a mistake in the
     * source and a mistake in somebody's document stop being the same kind of problem.
     */
    public static function kitMalformed(string $problem): self
    {
        $failure = new self($problem);

        $failure->field = 'kit';

        return $failure;
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
