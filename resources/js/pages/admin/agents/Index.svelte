<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as agentsIndex } from '@/routes/admin/agents';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Agents',
                href: agentsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import BotMessageSquare from 'lucide-svelte/icons/bot-message-square';
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { create, show } from '@/routes/admin/agents';
    import type { Agent } from '@/types';

    let { agents }: { agents: Agent[] } = $props();
</script>

<AppHead title="Agents" />

<h1 class="sr-only">Agents</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Agents"
        description="Accounts that play by themselves. An agent takes a seat like anybody else, but signs in with a token instead of a password."
    />

    <div>
        <Button asChild>
            {#snippet children(props)}
                <Link
                    href={toUrl(create())}
                    class={props.class}
                    data-test="create-agent-button"
                >
                    <BotMessageSquare class="h-4 w-4" aria-hidden="true" />
                    Create an agent
                </Link>
            {/snippet}
        </Button>
    </div>

    {#if agents.length === 0}
        <div class="rounded-lg border border-border p-8 text-center">
            <p class="font-medium">No agents yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Create one, seat it at a game from that game's roster, and then
                issue it a token.
            </p>
        </div>
    {:else}
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-muted/40">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium"
                            >Address</th
                        >
                        <th scope="col" class="px-4 py-3 font-medium">Seats</th>
                        <th scope="col" class="px-4 py-3 font-medium">Tokens</th
                        >
                        <th scope="col" class="px-4 py-3 font-medium"
                            >Last seen</th
                        >
                        <th scope="col" class="px-4 py-3 text-right">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {#each agents as agent (agent.id)}
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    href={toUrl(show(agent.id))}
                                    class="hover:underline"
                                >
                                    {agent.name}
                                </Link>
                            </td>
                            <td
                                class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                >{agent.email}</td
                            >
                            <td class="px-4 py-3 text-muted-foreground">
                                {agent.active_seats_count}
                            </td>
                            <td class="px-4 py-3">
                                {#if agent.credentials_count === 0}
                                    <Badge variant="secondary">None</Badge>
                                {:else}
                                    <Badge>{agent.credentials_count}</Badge>
                                {/if}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {agent.last_used_at_diff ?? 'Never'}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <Link
                                        href={toUrl(show(agent.id))}
                                        class="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                        data-test="show-agent-{agent.id}"
                                    >
                                        {agent.active_seats_count === 0
                                            ? 'Seat and issue a token'
                                            : 'Seats and tokens'}
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
