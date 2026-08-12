<script lang="ts">
    import type { LocationDetail, SystemPlanet } from '@/types';

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

    const deposits: { key: keyof SystemPlanet; label: string }[] = [
        { key: 'fuel', label: 'Fuel' },
        { key: 'metals', label: 'Metals' },
        { key: 'minerals', label: 'Minerals' },
    ];
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
                                {/each}
                            </tbody>
                        </table>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}
</div>
