<script lang="ts">
    import {
        clusterCells,
        groupByHex,
        hexCentre,
        hexDistance,
        hexPath,
        markRadius,
        trueDistance,
    } from '@/lib/cluster-hex';
    import type { HexSystem } from '@/lib/cluster-hex';
    import type { ClusterLocation } from '@/types';

    /*
     * The cluster as a strategic star map: regular hexagons tessellating the plane, each system in
     * the hex its `x, y` falls into, and `z` printed beside it rather than projected. The reason for
     * the form is that reach becomes countable — how many hexes apart two systems are is something
     * you read off the picture.
     *
     * What the map is *for* is worth stating, because the obvious answer is wrong. Every seed
     * produces a statistically identical cluster: the generator samples uniformly by volume and the
     * star mix is a quota, so there are always exactly 70 singles, 20 doubles, 9 triples and one
     * quadruple. "Is this cluster well spread" therefore has the same answer every time and is not
     * worth drawing. What changes with the seed is **where the rare stelliums landed** and what sits
     * within reach of what — so the map leans on making the triples and the lone quadruple stand out
     * by size and colour, and on making distance something you can read straight off it.
     *
     * Selection is owned by the page, not here. Only one `locationDetail` prop comes back from the
     * server, so the map and the table cannot each hold their own idea of what is open.
     */
    let {
        locations,
        selected = null,
        onSelect,
    }: {
        locations: ClusterLocation[];
        selected?: number | null;
        onSelect?: (location: ClusterLocation) => void;
    } = $props();

    /* The hex circumradius in SVG user units. Everything else scales off it. */
    const HEX_SIZE = 10;

    const cells = clusterCells();

    /*
     * The empty grid is one `<path>`. Seven hundred hexes are the backdrop — drawing them as
     * individual elements would put seven hundred nodes in the DOM to be hit-tested for no gain,
     * since only the ~93 occupied hexes are ever interactive.
     */
    const gridPath = cells.map((cell) => hexPath(cell, HEX_SIZE)).join('');

    const bounds = (() => {
        const centres = cells.map((cell) => hexCentre(cell, HEX_SIZE));
        const xs = centres.map((centre) => centre.cx);
        const ys = centres.map((centre) => centre.cy);
        /* Room for a hex's own corners, and below it for the label a system carries. */
        const padX = HEX_SIZE * 1.5;
        const padY = HEX_SIZE * 2.4;

        const minX = Math.min(...xs) - padX;
        const minY = Math.min(...ys) - padX;

        return {
            minX,
            minY,
            width: Math.max(...xs) + padX - minX,
            height: Math.max(...ys) + padY - minY,
        };
    })();

    const systems = $derived(groupByHex(locations, HEX_SIZE));

    /*
     * `star_count` is null until the stelliums stage runs, and that is not the same as zero — every
     * location gets at least one star. So the map has three states, not two: a cluster whose stars
     * are not decided renders as uniform marks with no ramp and no legend.
     */
    const hasStars = $derived(
        locations.some((location) => location.star_count !== null),
    );

    const hasPlanets = $derived(
        locations.some((location) => location.planet_count !== null),
    );


    let hovered = $state<number | null>(null);

    const byId = $derived(
        new Map(locations.map((location) => [location.id, location])),
    );

    const selectedLocation = $derived(
        selected === null ? null : (byId.get(selected) ?? null),
    );

    /* The readout follows the pointer when there is one, and falls back to what is open. */
    const readout = $derived(
        hovered === null
            ? selectedLocation
            : (byId.get(hovered) ?? selectedLocation),
    );

    /*
     * The distance from the open system to whatever is under the pointer — the thing a hex map is
     * for. Suppressed when they are the same system, where it would only ever read zero.
     */
    const measured = $derived(
        selectedLocation && readout && selectedLocation.id !== readout.id
            ? {
                  hexes: hexDistance(selectedLocation, readout),
                  climb: Math.abs(selectedLocation.z - readout.z),
                  real: trueDistance(selectedLocation, readout),
              }
            : null,
    );

    /*
     * The middle hex's own outline, drawn over the grid so it can be lit slightly brighter than its
     * neighbours. It is the map's one fixed landmark — every distance in the readout is measured from
     * it — so it has to be findable, but it carries no reading of its own and gets no caption.
     */
    const centrePath = hexPath({ x: 0, y: 0 }, HEX_SIZE);

    function fillFor(location: ClusterLocation): string {
        if (location.star_count === null) {
            return 'var(--space-ink)';
        }

        return `var(--stellium-${Math.min(location.star_count, 4)})`;
    }

    /**
     * What a hex is called in the readout and in assistive text.
     *
     * A stacked hex names its systems rather than the hex, because "3 systems" is the answer to a
     * question nobody asked — which ones is the useful part.
     */
    function labelFor(hex: HexSystem): string {
        if (hex.locations.length === 1) {
            return `#${hex.locations[0].ordinal}`;
        }

        return hex.locations
            .map((location) => `#${location.ordinal}`)
            .join(' ');
    }

    /**
     * The caption printed under a hex on the map itself: the system's height, and nothing else.
     *
     * `z` earns its place because it is the dimension the map flattened away and has nowhere else to
     * go. **The ordinal deliberately does not appear here.** It is an identifier rather than a
     * measurement, so it says nothing about where a system sits, and the readout under the map already
     * gives it on hover or focus. Printing it for some systems and not others also made the caption
     * mean two different things depending on the mark it sat under — which stelliums are worth finding
     * is already carried by size and colour, and does not need saying twice.
     */
    function captionFor(hex: HexSystem): string {
        if (hex.locations.length > 1) {
            return `${hex.locations.length}×`;
        }

        const location = hex.locations[0];

        return location.z > 0 ? `+${location.z}` : `${location.z}`;
    }

    /**
     * Open a hex, stepping through its systems when it holds more than one.
     *
     * Cycling is how a stacked hex stays reachable at all: two locations may share a column and a row
     * and differ only in height, so there is no position to click that distinguishes them.
     */
    function open(hex: HexSystem): void {
        if (!onSelect) {
            return;
        }

        const index = hex.locations.findIndex(
            (location) => location.id === selected,
        );

        onSelect(hex.locations[(index + 1) % hex.locations.length]);
    }
</script>

<div class="space-y-2">
    <p class="text-sm text-muted-foreground">
        {locations.length} systems on the plane, each in the hex its X and Y fall
        into, labelled with its Z — the height above or below it. Count hexes for
        reach.
        {#if hasStars}
            Brighter and larger means more stars.
        {:else}
            Star counts appear once the stelliums stage has run.
        {/if}
    </p>

    <!--
        Bounded and scrolled, the way the locations table is. Hex columns step 1.5 × the size while
        rows step √3 ×, so a square cluster is always taller than it is wide — drawn to fit a screen
        the captions collapse to a few unreadable pixels. Full width with the overflow scrolling keeps
        `z` legible, which is the whole reason the label is there.
    -->
    <div
        class="max-h-[80vh] overflow-auto rounded-lg border border-border"
        style:background="var(--space)"
        data-test="cluster-hex-map"
    >
        <svg
            viewBox="{bounds.minX} {bounds.minY} {bounds.width} {bounds.height}"
            class="block h-auto w-full"
            role="group"
            aria-label="Hex map of the cluster, {locations.length} systems"
        >
            <path
                d={gridPath}
                fill="none"
                stroke="var(--space-grid)"
                stroke-width="0.6"
            />

            <!--
                The middle hex, lit rather than labelled.

                It used to carry a crosshair and the word "centre", which put text into the picture at
                the one place systems cluster most densely — the label collided with a neighbouring
                system's height caption. A glow says "here" without competing for the same space, and
                it says nothing that has to be read.

                The glow is a blurred copy of the outline under a crisper one, which is what makes it
                bloom instead of merely being a thicker border. `--space-ink` is the map's own light,
                the same token the marks use; the palette is read as `var(--space*)` and never as
                `var(--color-*)`, since `@theme inline` leaves no custom property behind for the
                latter and the stroke would resolve to nothing.
            -->
            <defs>
                <filter
                    id="cluster-centre-glow"
                    x="-50%"
                    y="-50%"
                    width="200%"
                    height="200%"
                >
                    <feGaussianBlur stdDeviation="1.4" />
                </filter>
            </defs>
            <path
                d={centrePath}
                fill="none"
                stroke="var(--space-ink)"
                stroke-width="1.1"
                opacity="0.5"
                filter="url(#cluster-centre-glow)"
            />
            <path
                d={centrePath}
                fill="none"
                stroke="var(--space-ink)"
                stroke-width="0.6"
                opacity="0.3"
            />

            {#each systems as hex (hex.key)}
                {@const primary = hex.locations[0]}
                {@const isSelected = hex.locations.some(
                    (location) => location.id === selected,
                )}
                {@const isHovered = hex.locations.some(
                    (location) => location.id === hovered,
                )}
                <g
                    role="button"
                    tabindex="0"
                    class="cursor-pointer outline-none"
                    aria-label="{labelFor(
                        hex,
                    )} at {primary.x}, {primary.y}, height {primary.z}{hex
                        .locations.length > 1
                        ? `, sharing a hex with ${hex.locations.length - 1} more`
                        : ''}{primary.star_count === null
                        ? ''
                        : `, ${primary.star_count} stars`}"
                    onclick={() => open(hex)}
                    onkeydown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            open(hex);
                        }
                    }}
                    onmouseenter={() => (hovered = primary.id)}
                    onmouseleave={() => (hovered = null)}
                    onfocus={() => (hovered = primary.id)}
                    onblur={() => (hovered = null)}
                    data-test="cluster-hex-{primary.x}-{primary.y}"
                >
                    <!-- A generous transparent target: the marks are small and the hex is not. -->
                    <circle
                        cx={hex.cx}
                        cy={hex.cy}
                        r={HEX_SIZE * 0.9}
                        fill="transparent"
                    />

                    {#if isSelected || isHovered}
                        <circle
                            cx={hex.cx}
                            cy={hex.cy}
                            r={markRadius(primary.star_count, HEX_SIZE) + 2.6}
                            fill="none"
                            stroke="var(--space-ink)"
                            stroke-width={isSelected ? 1.4 : 0.8}
                            opacity={isSelected ? 1 : 0.6}
                        />
                    {/if}

                    {#if hex.locations.length > 1}
                        <!--
                            A stacked hex, drawn as an offset outline behind the mark. Roughly seven
                            systems a game share a hex with another and as many as four can pile into
                            one, so this is an ordinary state of the map rather than an edge case.
                        -->
                        <circle
                            cx={hex.cx + 1.7}
                            cy={hex.cy - 1.7}
                            r={markRadius(primary.star_count, HEX_SIZE)}
                            fill="var(--space)"
                            stroke={fillFor(primary)}
                            stroke-width="0.9"
                        />
                    {/if}

                    <circle
                        cx={hex.cx}
                        cy={hex.cy}
                        r={markRadius(primary.star_count, HEX_SIZE)}
                        style:fill={fillFor(primary)}
                    />

                    <text
                        x={hex.cx}
                        y={hex.cy + HEX_SIZE * 0.92}
                        text-anchor="middle"
                        font-size="4.6"
                        class="pointer-events-none tabular-nums"
                        style:fill="var(--space-ink)"
                        opacity="0.7"
                    >
                        {captionFor(hex)}
                    </text>
                </g>
            {/each}
        </svg>
    </div>

    <!--
        The readout, rather than a floating tooltip: it can hold the measurement as well as the
        system, it needs no positioning, and being a live region means a keyboard walk of the map is
        announced. Fixed height so the layout does not jump as the pointer crosses the grid.
    -->
    <p
        class="flex min-h-9 flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border px-3 py-2 text-sm"
        aria-live="polite"
        data-test="cluster-hex-readout"
    >
        {#if readout}
            <span class="font-medium">#{readout.ordinal}</span>
            <span class="tabular-nums text-muted-foreground">
                {readout.x}, {readout.y}, {readout.z} · {readout.radius.toFixed(
                    2,
                )} from centre
            </span>
            {#if readout.star_count !== null}
                <span class="text-muted-foreground">
                    {readout.star_count}
                    {readout.star_count === 1
                        ? 'star'
                        : 'stars'}{#if hasPlanets}, {readout.planet_count}
                        planets{/if}
                </span>
            {/if}
            {#if measured}
                <span class="tabular-nums" data-test="cluster-hex-measure">
                    {measured.hexes}
                    {measured.hexes === 1 ? 'hex' : 'hexes'} from #{selectedLocation?.ordinal}
                    · Δz {measured.climb} · ≈{measured.real.toFixed(1)} apart
                </span>
            {/if}
        {:else}
            <span class="text-muted-foreground">
                Point at a system for its coordinates. Open one to measure from
                it.
            </span>
        {/if}
    </p>

    {#if hasStars}
        <!--
            The legend is not optional: the star count must never be carried by colour alone. It is
            also the relief channel for the dimmest ramp step, which sits below 3:1 on the map's
            surface by design — a single star should recede.
        -->
        <ul
            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            {#each [1, 2, 3, 4] as count (count)}
                <li class="flex items-center gap-1.5">
                    <svg
                        viewBox="-6 -6 12 12"
                        class="h-3 w-3"
                        aria-hidden="true"
                    >
                        <circle
                            r={markRadius(count, HEX_SIZE) * 0.55}
                            style:fill="var(--stellium-{count})"
                        />
                    </svg>
                    {count}
                    {count === 1 ? 'star' : 'stars'}
                </li>
            {/each}
        </ul>
    {/if}
</div>
