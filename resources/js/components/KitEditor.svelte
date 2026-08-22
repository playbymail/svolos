<script lang="ts">
    import Plus from '@lucide/svelte/icons/plus';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import { untrack } from 'svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import type { Kit, UnitCatalogue } from '@/types';

    /*
     * The holdings of one kit, edited in place.
     *
     * ## Controlled state, where the rest of this application is uncontrolled
     *
     * Every other form here sets `value=` as an initial value and lets the DOM own it — see
     * `.ai/rules/frontend.md`. This one cannot: rows are added and removed at runtime, so what gets
     * posted has to be a projection of an array somebody is mutating, and a half-DOM-half-state form
     * would post whichever half the last interaction did not touch.
     *
     * So the array is the truth and `name` is derived from the **loop index**, which keeps the posted
     * lists contiguous for Laravel after a removal. The `{#each}` is keyed on `row.key`, a monotonic
     * counter and never the index: Svelte throws `each_key_duplicate` on a repeated key and the whole
     * subtree silently stops rendering, leaving a screen that looks stuck with one line in the
     * console. An index used as a key repeats the moment two rows swap.
     *
     * ## The catalogue comes from the server
     *
     * Which inventories a kind may sit in, and whether it carries a technology level, are rules that
     * live on `App\Enums\UnitType`. They are shipped in rather than restated here, so the pickers
     * cannot drift into offering something the server then refuses.
     */
    let {
        kit,
        catalogue,
        errors,
    }: {
        kit: Kit;
        catalogue: UnitCatalogue;
        errors: Record<string, string | undefined>;
    } = $props();

    type Row = {
        key: number;
        type: string;
        inventory: string;
        technologyLevel: number;
        quantity: number;
    };

    type EditableEntity = {
        type: string;
        label: string;
        rows: Row[];
    };

    let nextKey = 0;

    /*
     * Seeded from the server **once**, and then owned here. `untrack` says that deliberately: reading
     * a prop in a `$state` initialiser is otherwise a `state_referenced_locally` error, because the
     * usual mistake is meaning `$derived` and getting a value that never updates.
     *
     * A writable `$derived` — the pattern the status and separation pickers use — is wrong for this
     * one. It would rebuild the array whenever the prop changed, throwing away every unsaved row on
     * any partial reload, and its plain objects are not deeply reactive, so `bind:value` on a row
     * would update nothing.
     */
    const entities = $state<EditableEntity[]>(
        untrack(() =>
            kit.entities.map((entity) => ({
                type: entity.type,
                label: entity.label,
                rows: entity.holdings.map((holding) => ({
                    key: nextKey++,
                    type: holding.type,
                    inventory: holding.inventory,
                    technologyLevel: holding.technology_level,
                    quantity: holding.quantity,
                })),
            })),
        ),
    );

    function unitType(value: string) {
        return catalogue.unit_types.find((type) => type.value === value);
    }

    function unitLabel(value: string): string {
        return unitType(value)?.label ?? value;
    }

    function inventoryLabel(value: string): string {
        return (
            catalogue.inventories.find((inventory) => inventory.value === value)
                ?.label ?? value
        );
    }

    /**
     * The inventories this kind may sit in, which is the whole of the catalogue's placement rule.
     */
    function inventoriesFor(value: string) {
        const allowed = unitType(value)?.inventories ?? [];

        return catalogue.inventories.filter((inventory) =>
            allowed.includes(inventory.value),
        );
    }

    /**
     * Keep a row legal when its kind changes.
     *
     * A kind carries a technology level or it does not, and the two cases are mutually exclusive on
     * the server: `UnitHolding` refuses a level on a kind that has none, and refuses its absence on a
     * kind that has one. Snapping both the level and the inventory here means changing the kind never
     * leaves a row the server would reject — the alternative is a form that looks fine and fails on
     * save with a message about a field nobody touched.
     */
    function changeType(row: Row, value: string): void {
        row.type = value;

        const type = unitType(value);

        row.technologyLevel = type?.has_technology_level
            ? Math.max(row.technologyLevel, catalogue.minimum_technology_level)
            : catalogue.no_technology_level;

        if (!(type?.inventories ?? []).includes(row.inventory)) {
            row.inventory = type?.inventories[0] ?? row.inventory;
        }
    }

    function addRow(entity: EditableEntity): void {
        /* The first kind that can sit somewhere, so a new row starts legal rather than empty. */
        const type = catalogue.unit_types[0];

        entity.rows.push({
            key: nextKey++,
            type: type.value,
            inventory: type.inventories[0],
            technologyLevel: type.has_technology_level
                ? catalogue.maximum_technology_level
                : catalogue.no_technology_level,
            quantity: 1,
        });
    }

    function removeRow(entity: EditableEntity, key: number): void {
        entity.rows = entity.rows.filter((row) => row.key !== key);
    }
</script>

<div class="space-y-6">
    {#each entities as entity, entityIndex (entity.type)}
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div class="flex items-center justify-between gap-4">
                <h3 class="font-medium">{entity.label}</h3>
                <p class="text-sm text-muted-foreground">
                    {entity.rows.length}
                    {entity.rows.length === 1 ? 'holding' : 'holdings'}
                </p>
            </div>

            <!--
                The entity's kind is posted but never edited: a kit describes the two kinds a game
                opens with, and both are always present. The server refuses a document that leaves
                one out, so there is nothing here to choose between.
            -->
            <input
                type="hidden"
                name="entities[{entityIndex}][type]"
                value={entity.type}
            />

            {#if entity.rows.length === 0}
                <p class="text-sm text-muted-foreground">
                    Nothing here yet. An entity needs at least one holding.
                </p>
            {/if}

            <div class="space-y-3">
                {#each entity.rows as row, rowIndex (row.key)}
                    {@const prefix = `entities[${entityIndex}][holdings][${rowIndex}]`}
                    {@const path = `entities.${entityIndex}.holdings.${rowIndex}`}
                    {@const type = unitType(row.type)}

                    <div
                        class="grid items-end gap-3 sm:grid-cols-[2fr_2fr_1fr_1fr_auto]"
                        data-test="kit-holding-{entity.type}-{rowIndex}"
                    >
                        <div class="grid gap-1.5">
                            <Label for="{prefix}-type" class="text-xs">
                                Kind
                            </Label>
                            <Select
                                type="single"
                                name="{prefix}[type]"
                                value={row.type}
                                onValueChange={(value: string) =>
                                    changeType(row, value)}
                            >
                                <SelectTrigger
                                    id="{prefix}-type"
                                    class="w-full"
                                >
                                    {unitLabel(row.type)}
                                </SelectTrigger>
                                <SelectContent>
                                    {#each catalogue.unit_types as option (option.value)}
                                        <SelectItem value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    {/each}
                                </SelectContent>
                            </Select>
                            <InputError message={errors[`${path}.type`]} />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="{prefix}-inventory" class="text-xs">
                                Inventory
                            </Label>
                            <Select
                                type="single"
                                name="{prefix}[inventory]"
                                bind:value={row.inventory}
                            >
                                <SelectTrigger
                                    id="{prefix}-inventory"
                                    class="w-full"
                                >
                                    {inventoryLabel(row.inventory)}
                                </SelectTrigger>
                                <SelectContent>
                                    <!--
                                        Only the inventories this kind may sit in. A mine is a thing
                                        a colony operates, never a thing it is built from.
                                    -->
                                    {#each inventoriesFor(row.type) as option (option.value)}
                                        <SelectItem value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    {/each}
                                </SelectContent>
                            </Select>
                            <InputError message={errors[`${path}.inventory`]} />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="{prefix}-tl" class="text-xs">
                                Level
                            </Label>
                            <!--
                                Disabled rather than hidden for the kinds that have none, so the grid
                                does not reflow as somebody changes a kind. A disabled input posts
                                nothing, so the zero is written into a hidden field beside it — the
                                server wants `0` for those kinds and not an absent key, because `0`
                                is what the column holds.
                            -->
                            {#if type?.has_technology_level}
                                <Input
                                    id="{prefix}-tl"
                                    name="{prefix}[technology_level]"
                                    type="number"
                                    min={catalogue.minimum_technology_level}
                                    max={catalogue.maximum_technology_level}
                                    bind:value={row.technologyLevel}
                                />
                            {:else}
                                <Input
                                    id="{prefix}-tl"
                                    type="number"
                                    value={catalogue.no_technology_level}
                                    disabled
                                />
                                <input
                                    type="hidden"
                                    name="{prefix}[technology_level]"
                                    value={catalogue.no_technology_level}
                                />
                            {/if}
                            <InputError
                                message={errors[`${path}.technology_level`]}
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="{prefix}-quantity" class="text-xs">
                                Quantity
                            </Label>
                            <Input
                                id="{prefix}-quantity"
                                name="{prefix}[quantity]"
                                type="number"
                                min="1"
                                bind:value={row.quantity}
                            />
                            <InputError message={errors[`${path}.quantity`]} />
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onclick={() => removeRow(entity, row.key)}
                            data-test="remove-holding-{entity.type}-{rowIndex}"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                            <span class="sr-only">
                                Remove {unitLabel(row.type)}
                            </span>
                        </Button>
                    </div>
                {/each}
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                onclick={() => addRow(entity)}
                data-test="add-holding-{entity.type}"
            >
                <Plus class="h-4 w-4" aria-hidden="true" />
                Add a holding
            </Button>
        </section>
    {/each}
</div>
