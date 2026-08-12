<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import DashboardGameSection from '@/components/DashboardGameSection.svelte';
    import Heading from '@/components/Heading.svelte';
    import type { DashboardGame } from '@/types';

    /*
     * A section with no seats is **missing from the props**, not present and empty, so an absent
     * prop is the whole test for whether to render its heading. Do not default these to `[]`: that
     * would turn "you are in no games as a gamemaster" into a heading over nothing, which is a
     * different and much less useful thing to say than the blurb below.
     */
    let {
        gamemasterGames,
        playerGames,
    }: {
        gamemasterGames?: DashboardGame[];
        playerGames?: DashboardGame[];
    } = $props();

    const hasSeats = $derived(
        gamemasterGames !== undefined || playerGames !== undefined,
    );
</script>

<AppHead title="Dashboard" />

<h1 class="sr-only">Dashboard</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Your games"
        description="The games you hold a seat in, and the role you hold in each of them."
    />

    {#if hasSeats}
        {#if gamemasterGames}
            <DashboardGameSection
                slug="gamemaster"
                title="Running"
                description="Games you are the gamemaster of."
                games={gamemasterGames}
                manageable
            />
        {/if}

        {#if playerGames}
            <DashboardGameSection
                slug="player"
                title="Playing"
                description="Games you hold a player's seat in."
                games={playerGames}
            />
        {/if}
    {:else}
        <div
            class="rounded-lg border border-border p-8 text-center"
            data-test="no-seats"
        >
            <p class="font-medium">You are not in any games yet</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-muted-foreground">
                A seat at a game is given to you by an administrator, and it is
                what puts a game on this page. Once you have one, the games you
                run and the games you play will be listed here separately.
            </p>
        </div>
    {/if}
</div>
