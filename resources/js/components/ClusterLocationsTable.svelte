<script lang="ts">
    import type { ClusterLocation } from '@/types';

    /*
     * The whole cluster, scrolled rather than paged: reviewing a hundred locations means looking down
     * the list, and every row is already on the client. The container scrolls, not the page.
     */
    let { locations }: { locations: ClusterLocation[] } = $props();

    const hasStars = $derived(
        locations.some((location) => location.star_count !== null),
    );

    const farthest = $derived(
        locations.reduce(
            (distance, location) => Math.max(distance, location.radius),
            0,
        ),
    );
</script>

<div class="space-y-2">
    <p class="text-sm text-muted-foreground">
        {locations.length} locations, the farthest {farthest.toFixed(2)} from the
        centre. The centre itself is always empty.
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
                </tr>
            </thead>
            <tbody>
                {#each locations as location (location.id)}
                    <tr class="border-b border-border last:border-b-0">
                        <td class="px-4 py-1.5 text-muted-foreground">
                            {location.ordinal}
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
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
