<?php

namespace App\Generation;

use App\Enums\PlanetType;

/**
 * One planet as the generator drew it, before it is a row.
 *
 * Named for what it *is* rather than for the table it becomes, the way `Coordinates` is to
 * `Location` — and it could not be called `Planet` in any case, because the action that writes these
 * imports `App\Models\Planet`.
 *
 * The orbit is not here. A profile does not know its own ordinal: its position in `PlanetSystem` is
 * its position, exactly as a `Coordinates`' place in `LocationSet` is its ordinal. Nothing has to keep
 * the two in step because there is only one of them.
 */
final readonly class PlanetProfile
{
    /**
     * The habitability at which a world is worth calling habitable.
     *
     * Only the run summary uses this — "how many good places did this seed give me" is the question a
     * gamemaster reviews a planets run with. It is not a rule about anything: nothing refuses to do
     * something because a planet fell below it. About 3% of rocky worlds clear it, and no other type
     * can, so it comes out at roughly eleven worlds in a game.
     */
    public const int HABITABLE_FROM = 20;

    public function __construct(
        public PlanetType $type,
        public int $habitability,
        public int $fuel,
        public int $metals,
        public int $minerals,
    ) {}

    /**
     * Determine whether this is a world somebody would want to live on.
     */
    public function isHabitable(): bool
    {
        return $this->habitability >= self::HABITABLE_FROM;
    }
}
