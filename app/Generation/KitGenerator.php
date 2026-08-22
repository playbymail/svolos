<?php

namespace App\Generation;

use App\Enums\EntityType;
use Random\Randomizer;

/**
 * Draws the kit every player in one game begins with, for a gamemaster with no document to upload.
 *
 * Pure, like the generators beside it: a seed goes in, a `Kit` comes out, and nothing here touches
 * the database, the clock or the container. It is the generated half of the units stage's kit;
 * `Kit::fromJson()` is the uploaded half, and the two produce the same thing.
 *
 * ## What it draws, and what it must never draw
 *
 * **Quantities, and nothing else.** The *shape* of a kit — which kinds appear, in which inventory, at
 * which technology level — comes from `StartingUnits` unchanged, and the seed only moves how much of
 * each there is, inside a band of `VARIATION` percent.
 *
 * That line is exactly the one `.ai/rules/units.md` already draws: "The numbers in the manifests are
 * content and are meant to be tuned. The *shape* — which inventory each kind sits in — is not." Three
 * things the shape carries would be quietly destroyed by a generator that drew it instead:
 *
 * - the ship's engines are in `Inventory::Cargo` and nothing but the hull is in `Components`, which is
 *   why the ship cannot leave — the premise the whole game opens on;
 * - the colony's twenty light structural units enclose far more room than its people can fill,
 *   because the advance expedition built a city for an armada that never arrived;
 * - the food outweighs the buildings thirty to one, because what survived the voyage is not what the
 *   fleet's planners loaded.
 *
 * Jittering the quantities keeps all three: a colony with 17 or 23 structural units is still a city,
 * and a ship with 2 crated engines is still a ship that cannot move. Drawing the inventories, the
 * kinds or the levels would not.
 *
 * ## Every player in a game still gets exactly the same kit
 *
 * This draws **once per game**, and `GenerateUnits` hands the result to every seat unchanged. The
 * fairness rule in `StartingUnits` is about per-player variation and is untouched — what the seed now
 * decides is what *this game's* opening is, which is precisely what `HomeTemplateGenerator` decides
 * about the home system every player shares. See `Kit` for the whole of that argument.
 *
 * ## The draw schedule is load-bearing
 *
 * One roll per holding: entity kinds in `StartingUnits::entityTypes()` order, holdings within a kind
 * in the baseline's own order, nothing drawn between. Reordering that — or reordering a manifest in
 * `StartingUnits` — changes every kit a given seed produces without changing the odds of anything,
 * the same hazard `HomeTemplateGenerator` and `PlanetGenerator` both document. `KitGeneratorTest`
 * pins one seed's quantities as **literals** for that reason: comparing two runs of the same code
 * cannot catch a shifted stream, because it agrees with itself perfectly while every stored kit
 * quietly changes.
 */
class KitGenerator
{
    /**
     * How far a drawn quantity may fall either side of the baseline, as a percentage.
     *
     * Wide enough that two games read differently, narrow enough that nothing in the opening fiction
     * stops being true — see the class docblock. It is a percentage rather than a dice expression
     * because it applies to quantities spanning 1 to 1,000, and a fixed roll would be a rounding
     * error on the food and a catastrophe on the factories.
     */
    public const int VARIATION = 15;

    /**
     * The smallest quantity a draw may land on.
     *
     * `UnitHolding` refuses a quantity below one — "none of this" is the absence of a holding — so a
     * baseline of 1 shrunk by 15% has to floor here rather than produce a holding that cannot exist.
     */
    public const int MINIMUM_QUANTITY = 1;

    public function __construct(private readonly StartingUnits $baseline) {}

    /**
     * Draw a kit from a seed.
     */
    public function generate(int $seed): Kit
    {
        $randomizer = SeededRandomizer::for($seed);

        $entities = [];

        foreach ($this->baseline->entityTypes() as $type) {
            $entities[] = new KitEntity($type, $this->draw($randomizer, $type));
        }

        /* No file: a generated kit was not read from one, and the screen says so from that null. */
        return new Kit($entities, $seed);
    }

    /**
     * Draw one kind of entity's holdings, keeping everything but the quantities.
     *
     * @return list<UnitHolding>
     */
    private function draw(Randomizer $randomizer, EntityType $type): array
    {
        return array_map(
            fn (UnitHolding $holding): UnitHolding => new UnitHolding(
                $holding->type,
                $holding->inventory,
                $this->quantity($randomizer, $holding->quantity),
                $holding->technologyLevel,
            ),
            $this->baseline->for($type),
        );
    }

    /**
     * Scale one baseline quantity by a percentage inside the band.
     *
     * Integer arithmetic throughout, for the reason every measure in the catalogue is an integer: a
     * quantity decided in floating point is a quantity that can come out one short for no reason
     * anybody can see.
     *
     * **It rounds to nearest, and truncating here is a real bug rather than a rounding preference.**
     * A bare `intdiv()` takes 15% off a baseline of 2 and returns 1 — a 50% cut out of a 15% band,
     * because the whole of the shortfall lands on the one unit there is. That halved the ship's
     * crated engines, which is a number the opening fiction turns on. Adding half the divisor before
     * dividing keeps a small baseline where it is across the entire band: 2 stays 2, 1 stays 1, and
     * only quantities large enough for 15% to mean something actually move.
     *
     * `MINIMUM_QUANTITY` is still the floor, because `UnitHolding` refuses a quantity below one and
     * "none of this" is the absence of a holding rather than a row saying zero.
     */
    private function quantity(Randomizer $randomizer, int $baseline): int
    {
        $percentage = $randomizer->getInt(100 - self::VARIATION, 100 + self::VARIATION);

        return max(self::MINIMUM_QUANTITY, intdiv($baseline * $percentage + 50, 100));
    }
}
