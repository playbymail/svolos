<script lang="ts">
    import type {
        Inventory,
        LocationDetail,
        SystemUnit,
        SystemEntity,
    } from '@/types';

    /*
     * One location's stars and the planets around them, shown under the row that was expanded.
     *
     * `detail` is undefined while the partial reload is in flight and null when the location has no
     * stellium yet, which are different things: the first gets a skeleton, the second gets a sentence.
     * The parent decides which location is open; this only renders what came back.
     */
    let {
        detail,
        loading = false,
    }: {
        detail?: LocationDetail | null;
        loading?: boolean;
    } = $props();

    const deposits: {
        key: 'fuel' | 'metals' | 'minerals';
        label: string;
    }[] = [
        { key: 'fuel', label: 'Fuel' },
        { key: 'metals', label: 'Metals' },
        { key: 'minerals', label: 'Minerals' },
    ];

    /* Orbit, type, habitability, then one column a deposit. */
    const planetColumns = 3 + deposits.length;

    /**
     * Split an entity's units into the inventories it has any of.
     *
     * A unit joins the group **that already has its inventory**, wherever that group is in the
     * list, so one inventory can only ever produce one group. That is not tidiness: the group is the
     * key of a `{#each}`, and Svelte throws `each_key_duplicate` on a repeat — which stops the whole
     * panel rendering and leaves it showing its loading skeleton for ever, with nothing on the screen
     * to say why. Grouping by *neighbour* was the first version of this, and it turned an unordered
     * payload into a fatal error one file away from where the ordering was decided.
     *
     * A linear `find` rather than a keyed lookup because there are three inventories and never more:
     * a `Map` here is both heavier to read and, under `svelte/prefer-svelte-reactivity`, an invitation
     * to reach for `SvelteMap` for a local that is thrown away at the end of the call.
     *
     * Order still comes from the server, by first appearance, so there is no second ordering here to
     * disagree with `PresentsGeneration::presentEntities()`.
     */
    /**
     * Name one unit the way this panel shows it.
     *
     * A kind held at several technology levels is several entries, and without the level they read as
     * the same thing repeated — a ship built with LSTR-10 carrying crated LSTR-2 would show two
     * indistinguishable "Light Structural" lines. `0` means the kind has no level at all, which is
     * most of the raw commodities, and those are named by the label alone.
     *
     * The label rather than the report code (`LSTR-10`), because this is a screen and not a report:
     * most kinds have no report code assigned yet, so the codes would be blank for two entries in
     * three. See `App\Enums\UnitType::abbreviation()`.
     */
    function unitName(unit: SystemUnit): string {
        return unit.technology_level > 0
            ? `${unit.type_label} TL${unit.technology_level}`
            : unit.type_label;
    }

    function holdings(
        entity: SystemEntity,
    ): { inventory: Inventory; label: string; units: SystemUnit[] }[] {
        const groups: {
            inventory: Inventory;
            label: string;
            units: SystemUnit[];
        }[] = [];

        for (const unit of entity.units) {
            const group = groups.find(
                (candidate) => candidate.inventory === unit.inventory,
            );

            if (group) {
                group.units.push(unit);

                continue;
            }

            groups.push({
                inventory: unit.inventory,
                label: unit.assignment_label,
                units: [unit],
            });
        }

        return groups;
    }
</script>

<div class="bg-muted/40 px-4 py-3">
    {#if loading || detail === undefined}
        <div
            class="animate-pulse space-y-2"
            data-test="location-system-loading"
        >
            <div class="h-3 w-24 rounded bg-muted-foreground/20"></div>
            <div class="h-3 w-full rounded bg-muted-foreground/20"></div>
            <div class="h-3 w-5/6 rounded bg-muted-foreground/20"></div>
        </div>
    {:else if detail === null}
        <p class="text-sm text-muted-foreground">
            Nothing here yet — this location has no stellium until that stage
            has been generated.
        </p>
    {:else}
        <div class="space-y-3">
            {#each detail.stars as star (star.id)}
                <div>
                    <h4 class="text-xs font-medium text-muted-foreground">
                        Star {star.label} · {star.planets.length}
                        {star.planets.length === 1 ? 'planet' : 'planets'}
                    </h4>

                    {#if star.planets.length > 0}
                        <table class="mt-1 w-full text-left text-xs">
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        class="py-1 pr-3 font-normal">Orbit</th
                                    >
                                    <th
                                        scope="col"
                                        class="py-1 pr-3 font-normal">Type</th
                                    >
                                    <th
                                        scope="col"
                                        class="py-1 pr-3 font-normal"
                                        >Habitability</th
                                    >
                                    {#each deposits as deposit (deposit.key)}
                                        <th
                                            scope="col"
                                            class="py-1 pr-3 font-normal"
                                            >{deposit.label}</th
                                        >
                                    {/each}
                                </tr>
                            </thead>
                            <tbody>
                                {#each star.planets as planet (planet.id)}
                                    <tr data-test="planet-{planet.id}">
                                        <td class="py-0.5 pr-3 tabular-nums">
                                            {star.label}{planet.ordinal}
                                        </td>
                                        <td class="py-0.5 pr-3">
                                            {planet.type_label}
                                        </td>
                                        <td class="py-0.5 pr-3 tabular-nums">
                                            <!--
                                                Out of 25, and shown as such: a bare 0 next to a bare
                                                18 does not say what the scale is, and an asteroid
                                                field's 0 is a rule rather than a bad roll.
                                            -->
                                            {planet.habitability} / 25
                                        </td>
                                        {#each deposits as deposit (deposit.key)}
                                            <td
                                                class="py-0.5 pr-3 tabular-nums"
                                            >
                                                {planet[deposit.key]}
                                            </td>
                                        {/each}
                                    </tr>

                                    <!--
                                        Attached to the world rather than listed under the star: what
                                        is standing at a planet is a fact about that planet, and on
                                        all but a handful of worlds there is nothing here at all.
                                    -->
                                    {#each planet.entities as entity (entity.id)}
                                        <tr data-test="entity-{entity.id}">
                                            <td
                                                class="pb-1 pl-3"
                                                colspan={planetColumns}
                                            >
                                                <span class="font-medium">
                                                    {entity.type_label}
                                                </span>
                                                <span
                                                    class="text-muted-foreground"
                                                >
                                                    · {entity.player_name}
                                                </span>

                                                {#each holdings(entity) as group (group.inventory)}
                                                    <div
                                                        class="text-muted-foreground"
                                                    >
                                                        <span
                                                            class="font-medium"
                                                            >{group.label}</span
                                                        >
                                                        {#each group.units as unit, index (unit.id)}{index >
                                                            0
                                                                ? ', '
                                                                : ' '}{unitName(
                                                                unit,
                                                            )}
                                                            <span
                                                                class="tabular-nums"
                                                                >{unit.quantity}</span
                                                            >{/each}
                                                    </div>
                                                {/each}
                                            </td>
                                        </tr>
                                    {/each}
                                {/each}
                            </tbody>
                        </table>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}
</div>
