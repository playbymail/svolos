<script lang="ts">
    import Eye from 'lucide-svelte/icons/eye';
    import EyeOff from 'lucide-svelte/icons/eye-off';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import type { DashboardGame, GameStatus } from '@/types';

    let {
        title,
        description,
        games,
        slug,
    }: {
        title: string;
        description: string;
        games: DashboardGame[];
        /** Distinguishes this section's test hooks and heading id from the other one's. */
        slug: string;
    } = $props();

    /*
     * The toggle is local to this component, which is what makes the two sections independent: each
     * one gets its own instance and therefore its own state, with nothing to key or reset.
     *
     * It is also the whole reason archived games ship in the payload flagged rather than filtered —
     * see `App\Http\Controllers\DashboardController::present()`. Revealing them is a filter over
     * rows already on the client, so there is no request, no query parameter and no partial reload.
     */
    let showArchived = $state(false);

    const archivedCount = $derived(
        games.filter((game) => game.is_archived).length,
    );

    const visibleGames = $derived(
        showArchived ? games : games.filter((game) => !game.is_archived),
    );

    const statusVariants: Record<
        GameStatus,
        'default' | 'secondary' | 'outline' | 'destructive'
    > = {
        setup: 'outline',
        active: 'default',
        paused: 'secondary',
        completed: 'secondary',
        archived: 'destructive',
    };
</script>

<section class="space-y-4" aria-labelledby="{slug}-heading">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div id="{slug}-heading">
            <Heading variant="small" {title} {description} />
        </div>

        {#if archivedCount > 0}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-pressed={showArchived}
                onclick={() => (showArchived = !showArchived)}
                data-test="toggle-archived-{slug}"
            >
                {#if showArchived}
                    <EyeOff class="h-4 w-4" />
                    Hide archived
                {:else}
                    <Eye class="h-4 w-4" />
                    Show archived ({archivedCount})
                {/if}
            </Button>
        {/if}
    </div>

    {#if visibleGames.length === 0}
        <!--
            Reached only when every game in the section is archived and the toggle is off. The
            section still exists — an empty one is never rendered at all — so saying nothing here
            would look like a bug rather than like games that have been put away.
        -->
        <div
            class="rounded-lg border border-border p-6 text-center"
            data-test="all-archived-{slug}"
        >
            <p class="font-medium">
                {archivedCount === 1
                    ? 'This game has been archived'
                    : 'These games have been archived'}
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Show archived to see
                {archivedCount === 1 ? 'it' : 'them'}.
            </p>
        </div>
    {:else}
        <ul class="grid gap-3 sm:grid-cols-2" data-test="games-{slug}">
            {#each visibleGames as game (game.id)}
                <li
                    class="flex items-center gap-4 rounded-lg border border-border p-4"
                    data-test="game-{slug}-{game.id}"
                >
                    <code
                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                        aria-label="Short name"
                    >
                        {game.short_name}
                    </code>
                    <p class="min-w-0 flex-1 font-medium tracking-tight">
                        {game.name}
                    </p>
                    <Badge variant={statusVariants[game.status]}>
                        {game.status_label}
                    </Badge>
                </li>
            {/each}
        </ul>
    {/if}
</section>
