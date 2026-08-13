<?php

namespace App\Generation;

use App\Enums\PlanetType;
use Random\Randomizer;

/**
 * Draws the home system every player begins in, for a gamemaster who has no document to upload.
 *
 * Pure, like the four generators beside it: a seed goes in, a `HomeTemplate` comes out, and nothing
 * here touches the database, the clock or the container. It is the generated half of the home stellia
 * template stage; `HomeTemplate::fromJson()` is the uploaded half, and the two produce the same thing.
 *
 * ## The arrangement is fixed, and only the numbers are drawn
 *
 * `ARRANGEMENT` is nine planets in a fixed order, and no seed changes it. That is the opposite of
 * every other generator here and it is the point: a template exists to make the start of a game the
 * same for everybody, so the *shape* of a home is a decision the game makes once rather than a draw.
 * Nine planets is also the solar system's own count — see `PlanetGenerator::SOLAR_SYSTEM_ORBITS` —
 * so a generated home reads as an ordinary system rather than a special one.
 *
 * What the seed decides is what that shape is worth: the eight neighbours' habitability, and the home
 * world's three deposits. So two games generating a template from different seeds begin differently
 * from each other, while every player inside one game begins identically. That is the whole contract
 * of the stage, and it is why this draws at all rather than returning a constant.
 *
 * ## The home world is deliberately the third rock, at the top of the scale
 *
 * `HOME_ORDINAL` is 3 and `HOME_HABITABILITY` is 25 — the maximum `PlanetGenerator`'s rocky dice can
 * reach, and comfortably past `PlanetProfile::HABITABLE_FROM`. Neither is drawn: a home world that
 * came out barely habitable would be a game nobody wants, and a home world whose *position* moved
 * would make one player's system read differently from the next game's for no reason anybody could
 * use.
 *
 * ## The draw schedule is load-bearing
 *
 * Habitability for the eight neighbours in ordinal order, skipping the home world, and then the home
 * world's fuel, metals and minerals. Reordering that changes every generated template for a given
 * seed without changing the odds of anything — the same hazard `PlanetGenerator` documents about its
 * weight tables, and the reason the loop below skips rather than rolls-and-discards.
 *
 * The dice come from `PlanetGenerator`'s public tables rather than being restated, so a retuned
 * habitability table moves a generated template with it and there is one place to edit.
 */
class HomeTemplateGenerator
{
    /**
     * The home system, in order out from the star.
     *
     * Three rocky worlds, a belt, a gas giant, two ice giants, a second belt and a last ice giant. It
     * follows the zoning `PlanetGenerator` draws against — rocky inside, ice outside — so a home does
     * not look like something the cluster's own rules could never have produced.
     *
     * @var list<PlanetType>
     */
    public const array ARRANGEMENT = [
        PlanetType::Rocky,
        PlanetType::Rocky,
        PlanetType::Rocky,
        PlanetType::Asteroids,
        PlanetType::GasGiant,
        PlanetType::Icy,
        PlanetType::Icy,
        PlanetType::Asteroids,
        PlanetType::Icy,
    ];

    /**
     * Where the home world sits, counting from one.
     */
    public const int HOME_ORDINAL = 3;

    /**
     * How habitable the home world is.
     *
     * The top of the rocky scale, and fixed rather than drawn — every player begins somewhere worth
     * living, in every game.
     */
    public const int HOME_HABITABILITY = 25;

    /**
     * Draw a home template from a seed.
     */
    public function generate(int $seed): HomeTemplate
    {
        $randomizer = SeededRandomizer::for($seed);

        $planets = [];

        foreach (self::ARRANGEMENT as $index => $type) {
            if ($index + 1 === self::HOME_ORDINAL) {
                /*
                 * A placeholder in the list so the ordinals stay in step; its deposits are drawn after
                 * the loop, which is what keeps the schedule "eight habitabilities, then three
                 * deposits" rather than interleaving one player's home world into the middle of it.
                 */
                $planets[] = null;

                continue;
            }

            $planets[] = HomeTemplatePlanet::drawn(
                $type,
                $this->roll($randomizer, PlanetGenerator::HABITABILITY_DICE[$type->value]),
            );
        }

        $home = self::ARRANGEMENT[self::HOME_ORDINAL - 1];
        $deposits = PlanetGenerator::DEPOSIT_DICE[$home->value];

        $planets[self::HOME_ORDINAL - 1] = HomeTemplatePlanet::home(
            $home,
            self::HOME_HABITABILITY,
            $this->roll($randomizer, $deposits['fuel']),
            $this->roll($randomizer, $deposits['metals']),
            $this->roll($randomizer, $deposits['minerals']),
        );

        /* No file: a generated template was not read from one, and the screen says so from this null. */
        return new HomeTemplate($planets);
    }

    /**
     * Roll a `[dice, sides, modifier]` expression.
     *
     * The same shape as `PlanetGenerator::roll()`, which is private there on purpose — see the purity
     * rule in `.ai/rules/generation.md`: a shared helper would have to be handed a randomizer, and one
     * that built its own would restart the stream on every call.
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
}
