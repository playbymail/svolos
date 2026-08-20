<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as gamesIndex } from '@/routes/admin/games';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Games',
                href: gamesIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Dices from '@lucide/svelte/icons/dices';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import GameController from '@/actions/App/Http/Controllers/Admin/GameController';
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
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { toUrl } from '@/lib/utils';
    import { show as gameShow } from '@/routes/admin/games';
    import type { AdminGame, GameStatus } from '@/types';

    let {
        games,
        unarchivedCount,
    }: {
        games: AdminGame[];
        unarchivedCount: number;
    } = $props();

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

    /**
     * Describe what a delete is about to take with it.
     *
     * `game_seats.game_id` cascades, so deleting a game really does destroy its seats — the one place
     * in the application where a seat is destroyed rather than retired. The confirmation therefore
     * names the number, and counts retired seats in it, because those are rows that disappear too.
     */
    function seatsGoingWith(game: AdminGame): string {
        if (game.seats_count === 0) {
            return 'It has no seats.';
        }

        const seats =
            game.seats_count === 1 ? '1 seat' : `${game.seats_count} seats`;
        const retired = game.seats_count - game.active_seats_count;

        return retired === 0
            ? `Its ${seats} will be deleted with it.`
            : `Its ${seats} — including ${retired} retired — will be deleted with it.`;
    }
</script>

<AppHead title="Games" />

<h1 class="sr-only">Games</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Games"
        description="Every game in this installation and who sits at it. A game role is only ever about one game — it grants nothing anywhere else."
    />

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Create a game"
            description="A new game starts in setup with no seats. Add its roster from the game's own screen."
        />

        <Form
            {...GameController.store.form()}
            resetOnSuccess
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        type="text"
                        name="name"
                        required
                        autocomplete="off"
                        placeholder="The Long Retreat"
                    />
                    <InputError message={errors.name} />
                </div>

                <div class="grid gap-2">
                    <Label for="short_name">Short name</Label>
                    <Input
                        id="short_name"
                        type="text"
                        name="short_name"
                        required
                        maxlength={16}
                        autocapitalize="characters"
                        autocomplete="off"
                        placeholder="RUN-1"
                    />
                    <p class="text-xs text-muted-foreground">
                        Up to 16 letters, numbers and hyphens. It is uppercased
                        for you, and appears in turn reports and file names.
                    </p>
                    <InputError message={errors.short_name} />
                </div>

                <div class="sm:col-span-2">
                    <Button
                        type="submit"
                        class="w-full sm:w-auto"
                        disabled={processing}
                        data-test="create-game-button"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <Dices class="h-4 w-4" />
                        {/if}
                        Create game
                    </Button>
                </div>
            {/snippet}
        </Form>
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            title="All games"
            description={games.length === 0
                ? 'Nothing yet.'
                : `${unarchivedCount} of ${games.length} are not archived.`}
        />

        {#if games.length === 0}
            <div class="rounded-lg border border-border p-8 text-center">
                <p class="font-medium">No games yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create the first game above.
                </p>
            </div>
        {:else}
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Game
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Short name
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Seed
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Seats
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Created
                            </th>
                            <th scope="col" class="px-4 py-3 text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each games as game (game.id)}
                            <tr class="border-b border-border last:border-b-0">
                                <td class="px-4 py-3 font-medium">
                                    <Link
                                        href={toUrl(gameShow(game.id))}
                                        class="underline-offset-4 hover:underline"
                                        data-test="game-link-{game.id}"
                                    >
                                        {game.name}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <code
                                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                                    >
                                        {game.short_name}
                                    </code>
                                </td>
                                <!--
                                    Seeds are listed here as well as on each game's own screen: an
                                    administrator comparing two runs wants to see both numbers at
                                    once, and the seed is assigned before anybody opens the game.
                                -->
                                <td class="px-4 py-3">
                                    <code
                                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                                        data-test="game-seed-{game.id}"
                                    >
                                        {game.seed}
                                    </code>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        variant={statusVariants[game.status]}
                                    >
                                        {game.status_label}
                                    </Badge>
                                </td>
                                <td
                                    class="px-4 py-3 text-muted-foreground"
                                    data-test="game-seat-counts-{game.id}"
                                >
                                    {game.active_seats_count} of {game.seats_count}
                                    active
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <span title={game.created_at}>
                                        {game.created_at_diff ?? '—'}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                {#snippet children(props)}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        onclick={props.onClick}
                                                        data-test="delete-game-{game.id}"
                                                    >
                                                        <Trash2
                                                            class="h-4 w-4"
                                                        />
                                                        <span class="sr-only">
                                                            Delete {game.name}
                                                        </span>
                                                    </Button>
                                                {/snippet}
                                            </DialogTrigger>

                                            <DialogContent>
                                                <Form
                                                    {...GameController.destroy.form(
                                                        game.id,
                                                    )}
                                                    class="space-y-6"
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <DialogTitle>
                                                            Delete this game?
                                                        </DialogTitle>
                                                        <DialogDescription
                                                            data-test="delete-game-description-{game.id}"
                                                        >
                                                            {game.name}
                                                            ({game.short_name})
                                                            will be removed.
                                                            {seatsGoingWith(
                                                                game,
                                                            )}
                                                            This cannot be undone.
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
                                                                data-test="confirm-delete-game-{game.id}"
                                                            >
                                                                Delete game
                                                            </Button>
                                                        </DialogFooter>
                                                    {/snippet}
                                                </Form>
                                            </DialogContent>
                                        </Dialog>
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
