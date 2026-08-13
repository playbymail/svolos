<script lang="ts">
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import LocationSystemPanel from '@/components/LocationSystemPanel.svelte';
    import type { ClusterLocation, LocationDetail } from '@/types';

    /*
     * The whole cluster, scrolled rather than paged: reviewing a hundred locations means looking down
     * the list, and every row is already on the client. The container scrolls, not the page.
     *
     * The planets are the exception. There are several hundred of them and they do not ship with the
     * page — expanding a row asks the same screen for that one system through a partial reload, which
     * is why `detail` is a prop rather than something fetched here.
     *
     * Which row is open is the **page's** to hold, not this table's. Only one `locationDetail` comes
     * back from the server, so the hex map and this table cannot each keep their own idea of what is
     * expanded — they would fetch over each other and disagree about what the panel below is showing.
     * The fetch itself lives with the state, in `pages/gamemaster/games/Show.svelte`.
     */
    let {
        locations,
        detail,
        expanded = null,
        loading = false,
        onToggle,
    }: {
        locations: ClusterLocation[];
        detail?: LocationDetail | null;
        expanded?: number | null;
        loading?: boolean;
        onToggle?: (location: ClusterLocation) => void;
    } = $props();

    const hasStars = $derived(
        locations.some((location) => location.star_count !== null),
    );

    const hasPlanets = $derived(
        locations.some((location) => location.planet_count !== null),
    );

    /*
     * The map glows the hexes players begin at, and this column is what keeps that from being carried
     * by colour alone — the same reason the map's star-count legend is not optional.
     */
    const hasHomes = $derived(
        locations.some((location) => location.home_seat_id !== null),
    );

    const farthest = $derived(
        locations.reduce(
            (distance, location) => Math.max(distance, location.radius),
            0,
        ),
    );

    const columns = $derived(
        5 + (hasStars ? 1 : 0) + (hasPlanets ? 1 : 0) + (hasHomes ? 1 : 0),
    );
</script>

<div class="space-y-2">
    <p class="text-sm text-muted-foreground">
        {locations.length} locations, the farthest {farthest.toFixed(2)} from the
        centre. The centre itself is always empty.
        {#if hasPlanets}
            Open a location to see its stars and their planets.
        {/if}
    </p>

    <div class="max-h-96 overflow-auto rounded-lg border border-border">
        <table class="w-full text-left text-sm">
            <thead
                class="sticky top-0 border-b border-border bg-muted/95 backdrop-blur"
            >
                <tr>
                    <th scope="col" class="px-4 py-2 font-medium">#</th>
                    <th scope="col" class="px-4 py-2 font-medium">X</th>
                    <th scope="col" class="px-4 py-2 font-medium">Y</th>
                    <th scope="col" class="px-4 py-2 font-medium">Z</th>
                    <th scope="col" class="px-4 py-2 font-medium">Distance</th>
                    {#if hasStars}
                        <th scope="col" class="px-4 py-2 font-medium">Stars</th>
                    {/if}
                    {#if hasPlanets}
                        <th scope="col" class="px-4 py-2 font-medium"
                            >Planets</th
                        >
                    {/if}
                    {#if hasHomes}
                        <th scope="col" class="px-4 py-2 font-medium">Home</th>
                    {/if}
                </tr>
            </thead>
            <tbody>
                {#each locations as location (location.id)}
                    <tr
                        class="border-b border-border last:border-b-0 {expanded ===
                        location.id
                            ? 'bg-muted/50'
                            : ''}"
                    >
                        <td class="px-4 py-1.5 text-muted-foreground">
                            {#if hasPlanets}
                                <button
                                    type="button"
                                    class="flex items-center gap-1 hover:text-foreground"
                                    onclick={() => onToggle?.(location)}
                                    aria-expanded={expanded === location.id}
                                    data-test="expand-location-{location.id}"
                                >
                                    <ChevronRight
                                        class="h-3 w-3 transition-transform {expanded ===
                                        location.id
                                            ? 'rotate-90'
                                            : ''}"
                                    />
                                    {location.ordinal}
                                </button>
                            {:else}
                                {location.ordinal}
                            {/if}
                        </td>
                        <td class="px-4 py-1.5 tabular-nums">{location.x}</td>
                        <td class="px-4 py-1.5 tabular-nums">{location.y}</td>
                        <td class="px-4 py-1.5 tabular-nums">{location.z}</td>
                        <td
                            class="px-4 py-1.5 tabular-nums text-muted-foreground"
                        >
                            {location.radius.toFixed(2)}
                        </td>
                        {#if hasStars}
                            <td
                                class="px-4 py-1.5 tabular-nums"
                                data-test="location-stars-{location.id}"
                            >
                                {location.star_count ?? '—'}
                            </td>
                        {/if}
                        {#if hasPlanets}
                            <td
                                class="px-4 py-1.5 tabular-nums"
                                data-test="location-planets-{location.id}"
                            >
                                {location.planet_count ?? '—'}
                            </td>
                        {/if}
                        {#if hasHomes}
                            <td
                                class="px-4 py-1.5"
                                data-test="location-home-{location.id}"
                            >
                                {#if location.home_player_name}
                                    <span style:color="var(--home)">
                                        {location.home_player_name}
                                    </span>
                                {:else}
                                    <span class="text-muted-foreground">—</span>
                                {/if}
                            </td>
                        {/if}
                    </tr>

                    {#if expanded === location.id}
                        <tr class="border-b border-border last:border-b-0">
                            <td colspan={columns} class="p-0">
                                <LocationSystemPanel
                                    detail={loading ? undefined : detail}
                                    {loading}
                                />
                            </td>
                        </tr>
                    {/if}
                {/each}
            </tbody>
        </table>
    </div>
</div>
