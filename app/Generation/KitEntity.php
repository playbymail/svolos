<?php

namespace App\Generation;

use App\Enums\EntityType;
use InvalidArgumentException;

/**
 * One kind of entity in a kit, and everything it begins holding.
 *
 * The direct analogue of `HomeTemplatePlanet`: a `Kit` is a list of these the way a `HomeTemplate` is
 * a list of those, and this is where a refusal about *one* entity hangs. A kit that describes a
 * colony and a ship is two of these; `StartingUnits::openAirColony()` and `::ship()` are the two the
 * catalogue ships with, expressed as holdings rather than as an object.
 *
 * ## The constructor enforces the one rule a holding cannot see by itself
 *
 * `(entity_id, type, inventory, technology_level)` is unique in the `units` table, so two holdings
 * inside one entity agreeing on those three columns is a document the stage could not insert. A
 * `UnitHolding` knows its own three values and nothing about its neighbours, so the check has to live
 * one level up — here, where the whole set is in hand.
 *
 * It is an `InvalidArgumentException` rather than a `GenerationFailed` for the reason `UnitHolding`'s
 * is: this class is also how the *baseline* kits are expressed, and a duplicate in the source should
 * fail the moment a test loads the file. `Kit::fromJson()` is the one caller that has a gamemaster
 * behind it, and it catches these and rethrows them as failures naming the entity.
 */
final readonly class KitEntity
{
    /**
     * @param  list<UnitHolding>  $holdings
     */
    public function __construct(
        public EntityType $type,
        public array $holdings,
    ) {
        if ($holdings === []) {
            throw new InvalidArgumentException(
                sprintf('%s begins with nothing at all. Give it at least one holding.', $type->label())
            );
        }

        $this->guardNoDuplicateHoldings();
    }

    /**
     * Get what this entity weighs in total, in MU at `UnitType::SCALE`.
     */
    public function mass(): int
    {
        return array_sum(array_map(
            fn (UnitHolding $holding): int => $holding->mass(),
            $this->holdings,
        ));
    }

    /**
     * Get how much room everything it holds takes, in VU at `UnitType::SCALE`.
     *
     * Each holding is measured for **this** kind of entity, because a structural unit's assembled
     * volume depends on what it was assembled for — see `UnitType::assembledVolume()`. Summing
     * already-rounded holdings is correct: each is a separate grouping and pays its own rounding.
     *
     * Note what this is *not*. Structure in `Inventory::Components` **provides** an entity's capacity
     * rather than consuming it, so this figure is not "free space used" and must not be subtracted
     * from anything. See `.ai/rules/units.md` — none of that is modelled yet, and this is a total for
     * a gamemaster reviewing a kit, not an input to a capacity rule.
     */
    public function volume(): int
    {
        return array_sum(array_map(
            fn (UnitHolding $holding): int => $holding->volume($this->type),
            $this->holdings,
        ));
    }

    /**
     * Shape this for the json column, and for the document a gamemaster downloads.
     *
     * `technology_level` is written for every holding, including the kinds that have none, because a
     * document a person edits by hand should not make them remember which kinds take one. It is `0`
     * there rather than absent or null, which is the same value the column holds and for the same
     * reason — see `.ai/rules/units.md` on why the absent case is `0` and not `NULL`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'holdings' => array_map(
                fn (UnitHolding $holding): array => [
                    'type' => $holding->type->value,
                    'inventory' => $holding->inventory->value,
                    'technology_level' => $holding->technologyLevel,
                    'quantity' => $holding->quantity,
                ],
                $this->holdings,
            ),
        ];
    }

    /**
     * Refuse two holdings that would collide in the `units` unique key.
     *
     * @throws InvalidArgumentException
     */
    private function guardNoDuplicateHoldings(): void
    {
        $seen = [];

        foreach ($this->holdings as $holding) {
            $key = implode('/', [
                $holding->type->value,
                $holding->inventory->value,
                $holding->technologyLevel,
            ]);

            if (in_array($key, $seen, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s lists %s in %s twice. Combine them into one holding with the total quantity.',
                    $this->type->label(),
                    $holding->reportName() ?? $holding->type->label(),
                    $holding->inventory->label(),
                ));
            }

            $seen[] = $key;
        }
    }
}
