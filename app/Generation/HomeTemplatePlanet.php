<?php

namespace App\Generation;

use App\Enums\PlanetType;

/**
 * One planet of the home system every player begins in.
 *
 * Not a `PlanetProfile`, and the difference is the whole point of the template: a profile is a planet
 * somebody drew, complete in every column, while this is a planet **partly decided in advance**. The
 * type and the habitability are settled for everybody; the three deposits are settled only for the
 * home world itself.
 *
 * ## Null deposits mean "drawn per player", and that is what makes a planet the home world
 *
 * `fuel`, `metals` and `minerals` are null on every planet but one. `PlanetGenerator` fills those in
 * when it writes a home system, so each player's neighbours differ in what they are worth to mine
 * while looking identical on the map. The home world is the one planet whose deposits the template
 * fixes, so **carrying deposits is what being the home world means** — `isHome()` reads them rather
 * than a separate flag, because two ways of saying it could disagree.
 *
 * The uploaded document does carry an explicit `"home": true`, which is a different thing: that is a
 * gamemaster naming their intent, and the parser checks the deposits arrived with it.
 */
final readonly class HomeTemplatePlanet
{
    private function __construct(
        public PlanetType $type,
        public int $habitability,
        public ?int $fuel = null,
        public ?int $metals = null,
        public ?int $minerals = null,
    ) {}

    /**
     * Make a planet whose deposits every player draws for themselves.
     */
    public static function drawn(PlanetType $type, int $habitability): self
    {
        return new self($type, $habitability);
    }

    /**
     * Make the home world, whose every column is the same for everybody.
     */
    public static function home(PlanetType $type, int $habitability, int $fuel, int $metals, int $minerals): self
    {
        return new self($type, $habitability, $fuel, $metals, $minerals);
    }

    /**
     * Determine whether this is the world the players actually begin on.
     */
    public function isHome(): bool
    {
        return $this->fuel !== null;
    }
}
