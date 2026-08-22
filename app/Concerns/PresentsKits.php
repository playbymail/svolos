<?php

namespace App\Concerns;

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use App\Generation\Kit;
use App\Generation\KitEntity;
use App\Models\KitTemplate;

/**
 * Shapes kits for the screens that show them.
 *
 * Used by `Gamemaster\KitTemplateController` for the library and by `Gamemaster\GameController` for
 * the picker on the units stage card, which is why it is a trait rather than methods on one of them
 * — the same reason `PresentsGeneration` is shared between the two areas' game controllers.
 */
trait PresentsKits
{
    /**
     * Describe one saved kit for a list.
     *
     * `seed` and `file` are both carried through as nullable rather than collapsed into a single
     * "how did this arrive" string, because the screen shows them as different facts: a seed is a
     * number somebody can reuse, a filename is a document somebody still has.
     *
     * @return array<string, mixed>
     */
    protected function presentKitTemplate(KitTemplate $kitTemplate): array
    {
        $kit = $kitTemplate->kit();

        return [
            'id' => $kitTemplate->getKey(),
            'name' => $kitTemplate->name,
            'seed' => $kitTemplate->seed,
            'file' => $kitTemplate->file,
            'entities' => count($kit->entities),
            'holdings' => $kit->holdingCount(),
            'updated_at_diff' => $kitTemplate->updated_at->diffForHumans(),
        ];
    }

    /**
     * Describe one kit in full, for the editor.
     *
     * Mass and volume are totals computed here rather than per-holding figures shipped for the client
     * to add up. `.ai/rules/units.md` forbids the second thing — it would be a copy of `UnitType`
     * that can disagree with the original — and the first is the number a gamemaster judges an edited
     * kit by, which nothing on the screen could work out for itself.
     *
     * @return array<string, mixed>
     */
    protected function presentKit(Kit $kit): array
    {
        return [
            'seed' => $kit->seed,
            'file' => $kit->file,
            'entities' => array_map(
                fn (KitEntity $entity): array => [
                    'type' => $entity->type->value,
                    'label' => $entity->type->label(),
                    'mass' => UnitType::format($entity->mass()),
                    'volume' => UnitType::format($entity->volume()),
                    'holdings' => array_map(
                        fn ($holding): array => [
                            'type' => $holding->type->value,
                            'inventory' => $holding->inventory->value,
                            'technology_level' => $holding->technologyLevel,
                            'quantity' => $holding->quantity,
                            'report_name' => $holding->reportName(),
                        ],
                        $entity->holdings,
                    ),
                ],
                $kit->entities,
            ),
        ];
    }

    /**
     * Describe the catalogue the editor's pickers are built from.
     *
     * Shipped from the server rather than restated in TypeScript, so the enum stays the one
     * definition of which inventories a kind may sit in and which kinds carry a technology level. A
     * second copy on the client would be a copy that can disagree, and the disagreement would show
     * up as a holding the editor happily builds and `Kit` then refuses.
     *
     * @return array<string, mixed>
     */
    protected function presentUnitCatalogue(): array
    {
        return [
            'inventories' => array_map(
                fn (Inventory $inventory): array => [
                    'value' => $inventory->value,
                    'label' => $inventory->label(),
                ],
                Inventory::cases(),
            ),
            'entity_types' => array_map(
                fn (EntityType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ],
                EntityType::startingKinds(),
            ),
            'unit_types' => array_map(
                fn (UnitType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'abbreviation' => $type->abbreviation(),
                    'has_technology_level' => $type->hasTechnologyLevel(),
                    'inventories' => array_map(
                        fn (Inventory $inventory): string => $inventory->value,
                        $type->inventories(),
                    ),
                ],
                UnitType::cases(),
            ),
            'minimum_technology_level' => UnitType::MINIMUM_TECHNOLOGY_LEVEL,
            'maximum_technology_level' => UnitType::MAXIMUM_TECHNOLOGY_LEVEL,
            'no_technology_level' => UnitType::NO_TECHNOLOGY_LEVEL,
        ];
    }
}
