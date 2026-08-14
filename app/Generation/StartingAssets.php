<?php

namespace App\Generation;

use App\Enums\AssetAssignment;
use App\Enums\AssetType;
use App\Enums\EntityType;

/**
 * What every player begins with.
 *
 * Two entities and the assets they hold: a **colony** standing on the home world, and the **ship**
 * that brought its people there, in orbit above it. `docs/player-copy.txt` is where both come from —
 * a vessel whose main engines burned out during a voyage that took decades instead of months, and
 * below it a world an advance expedition prepared and then vanished from. "The mines remain. The
 * factories remain. The stores remain. The people do not."
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
 * `Engine` appears in the ship's manifest under `AssetAssignment::Cargo` and nowhere under
 * `Infrastructure`. That is the opening fiction written as data rather than as prose: the engines that
 * crossed the stars are gone, the spares are still crated, and a ship's ability to move will be read
 * off its **infrastructure** — so this ship cannot move until somebody installs them. Moving those two
 * units to `Infrastructure` would quietly undo the premise the whole game opens on.
 */
final class StartingAssets
{
    /**
     * What the advance expedition left on the ground.
     *
     * Buildings enough to shelter people, four mines and two factories already working, and depots of
     * everything a colony burns. Nothing here is crated: it was all built to be used where it stands.
     *
     * @return list<AssetHolding>
     */
    public function colony(): array
    {
        return [
            new AssetHolding(AssetType::Structure, AssetAssignment::Infrastructure, 20),
            new AssetHolding(AssetType::Mine, AssetAssignment::Operational, 4),
            new AssetHolding(AssetType::Factory, AssetAssignment::Operational, 2),
            new AssetHolding(AssetType::Fuel, AssetAssignment::Operational, 500),
            new AssetHolding(AssetType::Food, AssetAssignment::Operational, 1_000),
            new AssetHolding(AssetType::Metals, AssetAssignment::Operational, 600),
            new AssetHolding(AssetType::Minerals, AssetAssignment::Operational, 400),
            new AssetHolding(AssetType::Machinery, AssetAssignment::Operational, 150),
            new AssetHolding(AssetType::Supplies, AssetAssignment::Operational, 250),
        ];
    }

    /**
     * What is still aboard the ship that got them here.
     *
     * The hull is the one thing assigned to infrastructure — it is what the ship *is*, and the copy is
     * explicit that it may not stay a hull: "Its hull may become buildings." Everything else is in the
     * hold, the crated engines included.
     *
     * @return list<AssetHolding>
     */
    public function ship(): array
    {
        return [
            new AssetHolding(AssetType::Structure, AssetAssignment::Infrastructure, 300),
            /* Crated, not installed. See the class docblock: this is why the ship cannot leave. */
            new AssetHolding(AssetType::Engine, AssetAssignment::Cargo, 2),
            new AssetHolding(AssetType::Mine, AssetAssignment::Cargo, 2),
            new AssetHolding(AssetType::Factory, AssetAssignment::Cargo, 1),
            new AssetHolding(AssetType::Fuel, AssetAssignment::Cargo, 200),
            new AssetHolding(AssetType::Food, AssetAssignment::Cargo, 400),
            new AssetHolding(AssetType::Machinery, AssetAssignment::Cargo, 100),
            new AssetHolding(AssetType::Supplies, AssetAssignment::Cargo, 300),
        ];
    }

    /**
     * Get the kit for one kind of entity.
     *
     * The seam the action writes through, so that adding a third kind of starting entity is a case
     * here rather than a branch there.
     *
     * @return list<AssetHolding>
     */
    public function for(EntityType $type): array
    {
        return match ($type) {
            EntityType::Colony => $this->colony(),
            EntityType::Ship => $this->ship(),
        };
    }

    /**
     * Get the kinds of entity every player is given, in the order they are created.
     *
     * @return list<EntityType>
     */
    public function entityTypes(): array
    {
        return [EntityType::Colony, EntityType::Ship];
    }
}
