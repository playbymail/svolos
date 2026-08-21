<?php

namespace App\Generation;

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;

/**
 * What every player begins with.
 *
 * Two entities and the units they hold: a **colony** standing on the home world, and the **ship**
 * that brought its people there, in orbit above it. `docs/copy/player-introduction.txt` is where
 * both come from — a vessel whose main engines burned out during a voyage that took decades
 * instead of months, and below it a world an advance expedition prepared and then vanished from.
 * "The mines remain. The factories remain. The stores remain. The people do not."
 *
 * ## It draws nothing, and that is the fairness rule
 *
 * Every player gets the same kit, down to the last tonne, because the alternative is that the seed
 * decides who begins ahead. This is the same argument that makes every player's home *world*
 * identical deposits and all, and it is stronger here: the home template's neighbours are allowed to
 * differ because what a system is worth to mine is a thing to discover, while what you are handed on
 * turn one is not.
 *
 * So there is no `$seed` parameter, no `SeededRandomizer`, and nothing on this class to tune per
 * player. It is on `generationSources()` in `tests/Unit/GeneratorPurityTest.php` and deliberately
 * **not** on `seededGenerators()` — the second list asserts that a class opens a seeded stream, which
 * this one must never do. Being on the first is what catches somebody later reaching for
 * `Arr::random()` to make the kits "more interesting".
 *
 * ## The ship's engines are in the hold
 *
 * `Engine` appears in the ship's manifest under `Inventory::Cargo` and nowhere under
 * `Components`. That is the opening fiction written as data rather than as prose: the engines that
 * crossed the stars are gone, the spares are still crated, and a ship's ability to move will be read
 * off its **components** — so this ship cannot move until somebody installs them. Moving those two
 * units to `Components` would quietly undo the premise the whole game opens on.
 */
final class StartingUnits
{
    /**
     * What the advance expedition left on the ground.
     *
     * Buildings enough to shelter people, four mines and two factories already working, and depots of
     * everything a colony burns. Nothing here is crated: it was all built to be used where it stands.
     *
     * ## The structure is deliberately far larger than the colony needs
     *
     * Twenty light structural units at TL 10 enclose 200,000 VU — around **96%** of everything the
     * colony holds, and vastly more room than the people who arrive can fill. That is not a number
     * waiting to be trimmed.
     *
     * The expedition built a **city**, sized for the armada. Most of those ships never arrived, and
     * the first wave that did is gone. A player walks into empty streets built for a population that
     * is not coming, which is the premise the whole game opens on — see
     * `docs/copy/player-introduction.txt`. Cutting the quantity to fit the survivors would delete
     * the story from the data.
     *
     * @return list<UnitHolding>
     */
    public function openAirColony(): array
    {
        return [
            new UnitHolding(UnitType::LightStructure, Inventory::Components, 20, 10),
            new UnitHolding(UnitType::Mine, Inventory::Operational, 4, 10),
            new UnitHolding(UnitType::Factory, Inventory::Operational, 2, 10),
            new UnitHolding(UnitType::Fuel, Inventory::Operational, 500),
            new UnitHolding(UnitType::Food, Inventory::Operational, 1_000),
            new UnitHolding(UnitType::Metals, Inventory::Operational, 600),
            new UnitHolding(UnitType::Minerals, Inventory::Operational, 400),
            new UnitHolding(UnitType::Machinery, Inventory::Operational, 150, 10),
            new UnitHolding(UnitType::Supplies, Inventory::Operational, 250),
        ];
    }

    /**
     * What is still aboard the ship that got them here.
     *
     * The hull is the one thing assigned to components — it is what the ship *is*, and the copy is
     * explicit that it may not stay a hull: "Its hull may become buildings." Everything else is in the
     * hold, the crated engines included.
     *
     * @return list<UnitHolding>
     */
    public function ship(): array
    {
        return [
            new UnitHolding(UnitType::LightStructure, Inventory::Components, 300, 10),
            /* Crated, not installed. See the class docblock: this is why the ship cannot leave. */
            new UnitHolding(UnitType::Engine, Inventory::Cargo, 2, 10),
            new UnitHolding(UnitType::Mine, Inventory::Cargo, 2, 10),
            new UnitHolding(UnitType::Factory, Inventory::Cargo, 1, 10),
            new UnitHolding(UnitType::Fuel, Inventory::Cargo, 200),
            new UnitHolding(UnitType::Food, Inventory::Cargo, 400),
            new UnitHolding(UnitType::Machinery, Inventory::Cargo, 100, 10),
            new UnitHolding(UnitType::Supplies, Inventory::Cargo, 300),
        ];
    }

    /**
     * Get the kit for one kind of entity.
     *
     * The seam the action writes through, so that adding another kind of starting entity is a case
     * here rather than a branch there. A kind that starts nothing answers with an empty list.
     *
     * @return list<UnitHolding>
     */
    public function for(EntityType $type): array
    {
        return match ($type) {
            EntityType::OpenAirColony => $this->openAirColony(),
            EntityType::Ship => $this->ship(),
            EntityType::EnclosedColony, EntityType::OrbitalColony => [],
        };
    }

    /**
     * Get the kinds of entity every player is given, in the order they are created.
     *
     * Two of the four kinds of entity start a game. An enclosed colony and an orbital colony are
     * things a player builds, not things they are given, so `for()` answers them with an empty kit
     * rather than pretending otherwise.
     *
     * @return list<EntityType>
     */
    public function entityTypes(): array
    {
        return [EntityType::OpenAirColony, EntityType::Ship];
    }
}
