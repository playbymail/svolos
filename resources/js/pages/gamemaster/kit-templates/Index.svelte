<script module lang="ts">
    import { dashboard } from '@/routes';
    import { index as kitTemplatesIndex } from '@/routes/gamemaster/kit-templates';

    export const layout = {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Kits', href: kitTemplatesIndex() },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ChevronRight from '@lucide/svelte/icons/chevron-right';
    import Package from '@lucide/svelte/icons/package';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { create, show } from '@/routes/gamemaster/kit-templates';
    import type { KitTemplateSummary } from '@/types';

    /*
     * The library is private, so this is always and only the signed-in account's own shelf — the
     * server scopes the query and there is nothing here to filter or explain.
     */
    let { kits }: { kits: KitTemplateSummary[] } = $props();
</script>

<AppHead title="Kits" />

<h1 class="sr-only">Kits</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Kits"
        description="What every player in a game begins holding: a colony on the ground and the ship that brought its people there. A kit is yours alone, and you can use one at any game you run."
    />

    <div>
        <Button asChild>
            {#snippet children(props)}
                <Link
                    href={toUrl(create())}
                    class={props.class}
                    data-test="create-kit-button"
                >
                    <Package class="h-4 w-4" aria-hidden="true" />
                    New kit
                </Link>
            {/snippet}
        </Button>
    </div>

    {#if kits.length === 0}
        <div class="rounded-lg border border-border p-8 text-center">
            <p class="font-medium">No kits yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Draw one from a seed and edit it, or upload a document you
                already have. Until then, every game you generate opens with a
                kit drawn from its own seed.
            </p>
        </div>
    {:else}
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Origin</th
                        >
                        <th scope="col" class="px-4 py-3 font-medium">
                            Holdings
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Last saved
                        </th>
                        <th scope="col" class="px-4 py-3 text-right">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {#each kits as kit (kit.id)}
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    href={toUrl(show(kit.id))}
                                    class="hover:underline"
                                >
                                    {kit.name}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <!--
                                    Two nullable facts rather than one string: a seed is a number
                                    somebody can reuse, a filename is a document they still have, and
                                    both null means they wrote it here.
                                -->
                                {#if kit.file !== null}
                                    <span class="font-mono text-xs"
                                        >{kit.file}</span
                                    >
                                {:else if kit.seed !== null}
                                    <Badge variant="outline">
                                        seed {kit.seed}
                                    </Badge>
                                {:else}
                                    <span class="text-xs">Written by hand</span>
                                {/if}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {kit.holdings}
                                <span class="text-xs">
                                    across {kit.entities}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {kit.updated_at_diff}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <Link
                                        href={toUrl(show(kit.id))}
                                        class="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                        data-test="show-kit-{kit.id}"
                                    >
                                        Edit
                                        <ChevronRight
                                            class="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
