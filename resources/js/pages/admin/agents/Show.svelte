<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as agentsIndex } from '@/routes/admin/agents';

    export const layout = (props: { agent: { name: string; id: number } }) => ({
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Agents',
                href: agentsIndex(),
            },
            {
                title: props.agent.name,
                href: agentsIndex(),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, page } from '@inertiajs/svelte';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import RefreshCw from 'lucide-svelte/icons/refresh-cw';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import UserPlus from 'lucide-svelte/icons/user-plus';
    import AgentCredentialController from '@/actions/App/Http/Controllers/Admin/AgentCredentialController';
    import AgentSeatController from '@/actions/App/Http/Controllers/Admin/AgentSeatController';
    import AgentTokenPanel from '@/components/AgentTokenPanel.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
        DialogTrigger,
    } from '@/components/ui/dialog';
    import { Label } from '@/components/ui/label';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import { Spinner } from '@/components/ui/spinner';
    import { toUrl } from '@/lib/utils';
    import { index as gamesIndex } from '@/routes/admin/games';
    import type { Agent, AgentSeat, AssignableGame } from '@/types';

    let {
        agent,
        seats,
        assignableGames,
    }: {
        agent: Agent;
        seats: AgentSeat[];
        assignableGames: AssignableGame[];
    } = $props();

    /* Bound to the game picker, which renders the hidden `game_id` input the form posts. */
    let gameId = $state<string>('');

    const selectedGameLabel = $derived(
        assignableGames.find((game) => String(game.id) === gameId)?.name ??
            'Choose a game',
    );

    /*
     * The one moment a minted token is readable. It rides on the page object rather than in props,
     * so it is present on exactly the response that follows the mint and gone on the next — which is
     * the whole of "shown once". See `AgentTokenPanel.svelte`.
     */
    const mintedToken = $derived(page.flash?.agentToken);
</script>

<AppHead title={agent.name} />

<h1 class="sr-only">{agent.name}</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title={agent.name}
        description="Created {agent.created_at_diff ??
            'recently'}. Signs in with a token, never a password."
    />

    {#if mintedToken}
        <AgentTokenPanel flash={mintedToken} />
    {/if}

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Seats"
            description="A token belongs to one seat, so it only ever works in that seat's game. Seat {agent.name} at a game here, then issue it a token."
        />

        {#if assignableGames.length > 0}
            <Form
                {...AgentSeatController.store.form(agent.id)}
                options={{ preserveScroll: true }}
                onSuccess={() => (gameId = '')}
                class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                {#snippet children({ errors, processing })}
                    <div class="grid gap-2">
                        <Label for="game_id">Seat at a game</Label>
                        <Select
                            type="single"
                            name="game_id"
                            bind:value={gameId}
                        >
                            <SelectTrigger id="game_id" class="w-full">
                                {selectedGameLabel}
                            </SelectTrigger>
                            <SelectContent>
                                {#each assignableGames as game (game.id)}
                                    <SelectItem
                                        value={String(game.id)}
                                        label="{game.name} ({game.short_name})"
                                    />
                                {/each}
                            </SelectContent>
                        </Select>
                        <p class="text-sm text-muted-foreground">
                            Joins as a player. Make it a gamemaster from that
                            game's roster if you need to.
                        </p>
                        <InputError message={errors.game_id} />
                    </div>

                    <Button
                        type="submit"
                        disabled={processing || gameId === ''}
                        data-test="seat-agent-button"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <UserPlus class="h-4 w-4" aria-hidden="true" />
                        {/if}
                        Add seat
                    </Button>
                {/snippet}
            </Form>
        {/if}

        {#if seats.length === 0}
            <div class="rounded-lg border border-border p-8 text-center">
                <p class="font-medium">Not seated anywhere yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {#if assignableGames.length > 0}
                        Pick a game above. You can issue a token once {agent.name}
                        has a seat.
                    {:else}
                        There is no game to seat {agent.name} at — every game either
                        has it already or is archived.
                    {/if}
                </p>
                <Button variant="secondary" asChild>
                    {#snippet children(props)}
                        <Link
                            href={toUrl(gamesIndex())}
                            class="{props.class} mt-4"
                            data-test="go-to-games"
                        >
                            Go to games
                        </Link>
                    {/snippet}
                </Button>
            </div>
        {:else}
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium"
                                >Game</th
                            >
                            <th scope="col" class="px-4 py-3 font-medium"
                                >Role</th
                            >
                            <th scope="col" class="px-4 py-3 font-medium"
                                >Token</th
                            >
                            <th scope="col" class="px-4 py-3 font-medium"
                                >Last used</th
                            >
                            <th scope="col" class="px-4 py-3 text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each seats as seat (seat.id)}
                            <tr class="border-b border-border last:border-b-0">
                                <td class="px-4 py-3">
                                    <span class="font-medium"
                                        >{seat.game.name}</span
                                    >
                                    <span class="text-muted-foreground">
                                        ({seat.game.short_name})</span
                                    >
                                    {#if !seat.is_active}
                                        <Badge
                                            variant="secondary"
                                            class="ms-2"
                                            data-test="retired-seat-{seat.id}"
                                        >
                                            Retired
                                        </Badge>
                                    {/if}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground"
                                    >{seat.role_label}</td
                                >
                                <td class="px-4 py-3">
                                    {#if seat.has_credential}
                                        <Badge data-test="has-token-{seat.id}"
                                            >Issued {seat.issued_at_diff}</Badge
                                        >
                                        {#if seat.issued_by}
                                            <span
                                                class="ms-2 text-xs text-muted-foreground"
                                                >by {seat.issued_by}</span
                                            >
                                        {/if}
                                    {:else}
                                        <Badge variant="secondary">None</Badge>
                                    {/if}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {seat.last_used_at_diff ?? '—'}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        {#if seat.can_issue}
                                            <Form
                                                {...AgentCredentialController.store.form(
                                                    [agent.id, seat.id],
                                                )}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                        data-test="issue-token-{seat.id}"
                                                    >
                                                        {#if seat.has_credential}
                                                            <RefreshCw
                                                                class="h-4 w-4"
                                                                aria-hidden="true"
                                                            />
                                                            Replace token
                                                        {:else}
                                                            <KeyRound
                                                                class="h-4 w-4"
                                                                aria-hidden="true"
                                                            />
                                                            Issue token
                                                        {/if}
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        {/if}

                                        {#if seat.has_credential}
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    {#snippet children(props)}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            onclick={props.onClick}
                                                            data-test="revoke-token-{seat.id}"
                                                        >
                                                            <Trash2
                                                                class="h-4 w-4"
                                                                aria-hidden="true"
                                                            />
                                                            <span
                                                                class="sr-only"
                                                                >Revoke the
                                                                token for {seat
                                                                    .game
                                                                    .name}</span
                                                            >
                                                        </Button>
                                                    {/snippet}
                                                </DialogTrigger>

                                                <DialogContent>
                                                    <Form
                                                        {...AgentCredentialController.destroy.form(
                                                            [agent.id, seat.id],
                                                        )}
                                                        class="space-y-6"
                                                    >
                                                        {#snippet children({
                                                            processing,
                                                        })}
                                                            <DialogTitle>
                                                                Revoke this
                                                                token?
                                                            </DialogTitle>
                                                            <DialogDescription>
                                                                {agent.name} will
                                                                stop being able to
                                                                act in {seat
                                                                    .game.name}
                                                                immediately. The seat
                                                                stays as it is — issue
                                                                a new token whenever
                                                                you want it playing
                                                                again.
                                                            </DialogDescription>

                                                            <DialogFooter
                                                                class="gap-2"
                                                            >
                                                                <DialogClose
                                                                    asChild
                                                                >
                                                                    {#snippet children(
                                                                        props,
                                                                    )}
                                                                        <Button
                                                                            variant="secondary"
                                                                            onclick={props.onClick}
                                                                        >
                                                                            Cancel
                                                                        </Button>
                                                                    {/snippet}
                                                                </DialogClose>
                                                                <Button
                                                                    type="submit"
                                                                    variant="destructive"
                                                                    disabled={processing}
                                                                    data-test="confirm-revoke-token-{seat.id}"
                                                                >
                                                                    Revoke token
                                                                </Button>
                                                            </DialogFooter>
                                                        {/snippet}
                                                    </Form>
                                                </DialogContent>
                                            </Dialog>
                                        {/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>
</div>
