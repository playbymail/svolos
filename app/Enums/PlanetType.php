<?php

namespace App\Enums;

/**
 * What kind of body a planet is.
 *
 * Mostly flavour: every planet, whatever its type, has the same habitability and the same three
 * deposits. The type decides the *dice* those are drawn from, not which columns exist — see
 * `App\Generation\PlanetGenerator`, where each type is a row in three tables.
 *
 * The distribution follows our own solar system — four rocky worlds, one belt, two gas giants and two
 * ice giants — but that shape comes from **where** a planet sits rather than from a quota over the
 * types. `Asteroids` is the one case with a rule of its own: habitability is a flat zero rather than
 * a draw, and its metals and minerals reach higher than any other type's to pay for that.
 */
enum PlanetType: string
{
    case Rocky = 'rocky';
    case Asteroids = 'asteroids';
    case GasGiant = 'gas_giant';
    case Icy = 'icy';

    /**
     * Get the human readable label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rocky => 'Rocky',
            self::Asteroids => 'Asteroids',
            self::GasGiant => 'Gas giant',
            self::Icy => 'Icy',
        };
    }
}
