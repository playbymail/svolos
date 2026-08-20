<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import {
        index as gamesIndex,
        show as gameShow,
    } from '@/routes/admin/games';
    import type { AdminGame, BreadcrumbItem } from '@/types';

    /**
     * The last crumb is the game's own name, which only the server knows, so the layout export is a
     * *function*: the Svelte adapter calls it with the page props and spreads the result into the
     * layout chosen in `app.ts`. The alternative — importing a layout here — would take this page out
     * of the central resolution the rest of the application uses.
     */
    export const layout = (props: {
        game: AdminGame;
    }): { breadcrumbs: BreadcrumbItem[] } => ({
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Games',
                href: gamesIndex(),
            },
            {
                title: props.game.name,
                href: gameShow(props.game.id),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import RotateCcw from '@lucide/svelte/icons/rotate-ccw';
    import Save from '@lucide/svelte/icons/save';
    import UserMinus from '@lucide/svelte/icons/user-minus';
    import UserPlus from '@lucide/svelte/icons/user-plus';
    import GameController from '@/actions/App/Http/Controllers/Admin/GameController';
    import GameSeatController from '@/actions/App/Http/Controllers/Admin/GameSeatController';
    import AppHead from '@/components/AppHead.svelte';
    import GameSeatRoleForm from '@/components/GameSeatRoleForm.svelte';
    import GameSeedForm from '@/components/GameSeedForm.svelte';
    import GenerationStageCard from '@/components/GenerationStageCard.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import { Spinner } from '@/components/ui/spinner';
    /*
     * `AdminGame` is deliberately not re-imported here: the two `<script>` blocks share one module
     * scope, so the import in `<script module>` above is already visible and a second one is a
     * duplicate-identifier error under `svelte-check`.
     */
    import type {
        AdminGameSeat,
        AssignableAccount,
        GameRole,
        GameRoleOption,
        GameStatusOption,
        GenerationSummary,
    } from '@/types';

    let {
        game,
        generation,
        seats,
        assignableAccounts,
        roles,
        statuses,
    }: {
        game: AdminGame;
        generation: GenerationSummary;
        seats: AdminGameSeat[];
        assignableAccounts: AssignableAccount[];
        roles: GameRoleOption[];
        statuses: GameStatusOption[];
    } = $props();

    /*
     * The metadata form's status picker. A writable `$derived` off the server's value, so a refused
     * save snaps the picker back rather than leaving it showing a status the game does not have.
     */
    let status = $derived<string>(game.status);

    const statusLabel = $derived(
        statuses.find((option) => option.value === status)?.label ??
            'Choose a status',
    );

    /*
     * `player` rather than `roles[0]`: the default on a form that hands out a role should be the
     * lesser of the two whatever order the enum happens to list them in.
     */
    const defaultRole: GameRole = 'player';

    let seatRole = $state<string>(defaultRole);
    let seatAccount = $state<string>('');

    const seatRoleLabel = $derived(
        roles.find((option) => option.value === seatRole)?.label ??
            'Choose a role',
    );

    const seatAccountLabel = $derived(
        assignableAccounts.find((account) => String(account.id) === seatAccount)
            ?.name ?? 'Choose an account',
    );

    const retiredCount = $derived(game.seats_count - game.active_seats_count);
</script>

<AppHead title={game.name} />

<h1 class="sr-only">{game.name}</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title={game.name}
        description="{game.short_name} · {game.status_label} · {game.active_seats_count} of {game.seats_count} seats active · created {game.created_at}"
    />

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Details"
            description="The short name appears in turn reports and file names, so it is uppercased and limited to letters, numbers and hyphens."
        />

        <Form
            {...GameController.update.form(game.id)}
            options={{ preserveScroll: true }}
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2"
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
                        value={game.name}
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
                        value={game.short_name}
                    />
                    <InputError message={errors.short_name} />
                </div>

                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <Select type="single" name="status" bind:value={status}>
                        <SelectTrigger id="status" class="w-full">
                            {statusLabel}
                        </SelectTrigger>
                        <SelectContent>
                            {#each statuses as option (option.value)}
                                <SelectItem
                                    value={option.value}
                                    label={option.label}
                                />
                            {/each}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div class="flex items-end">
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="save-game-button"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <Save class="h-4 w-4" />
                        {/if}
                        Save changes
                    </Button>
                </div>
            {/snippet}
        </Form>
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Seed"
            description="The number this game's randomness is drawn from. It is assigned when the game is created, and it can only be changed while the game is in setup."
        />

        <GameSeedForm action={GameController.updateSeed.form(game.id)} {game} />
    </section>

    <section class="space-y-4">
        <!--
            Read-only on purpose. Running the generators belongs to the gamemaster's screen — there is
            no admin route that does it — but which seed produced which world is exactly what an
            administrator needs when a game has to be explained or reproduced.
        -->
        <Heading
            variant="small"
            title="Generation"
            description="The world this game is played in, and the seeds it was built from. The gamemaster runs the generators from their own screen."
        />

        <div class="space-y-4" data-test="generation-summary">
            {#each generation.stages as stage (stage.stage)}
                <GenerationStageCard {stage} />
            {/each}
        </div>
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Add a seat"
            description="A game role applies to this game only — a gamemaster here is not an administrator anywhere."
        />

        {#if assignableAccounts.length === 0}
            <div class="rounded-lg border border-border p-4 text-sm">
                <p class="font-medium">Every account already has a seat here</p>
                <p class="mt-1 text-muted-foreground">
                    Accounts with a retired seat are not offered again —
                    bringing somebody back is a reactivation below, never a
                    second seat.
                </p>
            </div>
        {:else}
            <Form
                {...GameSeatController.store.form(game.id)}
                resetOnSuccess
                onSuccess={() => {
                    seatRole = defaultRole;
                    seatAccount = '';
                }}
                options={{ preserveScroll: true }}
                class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_14rem]"
            >
                {#snippet children({ errors, processing })}
                    <div class="grid gap-2">
                        <Label for="user_id">Account</Label>
                        <Select
                            type="single"
                            name="user_id"
                            bind:value={seatAccount}
                        >
                            <SelectTrigger id="user_id" class="w-full">
                                {seatAccountLabel}
                            </SelectTrigger>
                            <SelectContent>
                                {#each assignableAccounts as account (account.id)}
                                    <SelectItem
                                        value={String(account.id)}
                                        label="{account.name} ({account.email})"
                                    />
                                {/each}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.user_id} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Game role</Label>
                        <Select type="single" name="role" bind:value={seatRole}>
                            <SelectTrigger id="role" class="w-full">
                                {seatRoleLabel}
                            </SelectTrigger>
                            <SelectContent>
                                {#each roles as option (option.value)}
                                    <SelectItem
                                        value={option.value}
                                        label={option.label}
                                    />
                                {/each}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>

                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            class="w-full sm:w-auto"
                            disabled={processing || seatAccount === ''}
                            data-test="add-seat-button"
                        >
                            {#if processing}
                                <Spinner />
                            {:else}
                                <UserPlus class="h-4 w-4" />
                            {/if}
                            Add seat
                        </Button>
                    </div>
                {/snippet}
            </Form>
        {/if}
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Roster"
            description={retiredCount === 0
                ? 'Everybody currently in the game.'
                : `${game.active_seats_count} active and ${retiredCount} retired. Retired seats are kept because the game's history keeps referring to them, and reactivating one is how somebody comes back.`}
        />

        {#if seats.length === 0}
            <div class="rounded-lg border border-border p-8 text-center">
                <p class="font-medium">No seats yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Add the first seat above.
                </p>
            </div>
        {:else}
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Account
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Seat
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Joined
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Game role
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Home
                            </th>
                            <th scope="col" class="px-4 py-3 text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each seats as seat (seat.id)}
                            <tr class="border-b border-border last:border-b-0">
                                <td class="px-4 py-3">
                                    <span class="font-medium">
                                        {seat.user_name}
                                    </span>
                                    <p class="text-muted-foreground">
                                        {seat.user_email}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        variant={seat.is_active
                                            ? 'default'
                                            : 'secondary'}
                                        data-test="seat-state-{seat.id}"
                                    >
                                        {seat.is_active ? 'Active' : 'Retired'}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {seat.created_at_diff ?? '—'}
                                </td>
                                <td class="px-4 py-3">
                                    <GameSeatRoleForm
                                        action={GameSeatController.updateRole.form(
                                            { game: game.id, seat: seat.id },
                                        )}
                                        {seat}
                                        {roles}
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <!--
                                        Read-only, and this screen's only sight of it: there is no
                                        hex map here, because building a game's world is the
                                        gamemaster's. It is worth carrying all the same, since the
                                        status form above refuses to make a game active while a
                                        player has nowhere to begin, and a screen that reported that
                                        without being able to say who would be no help.
                                    -->
                                    {#if seat.home}
                                        <span
                                            class="font-medium"
                                            data-test="seat-home-{seat.id}"
                                        >
                                            #{seat.home.ordinal}
                                        </span>
                                        <p
                                            class="tabular-nums text-muted-foreground"
                                        >
                                            {seat.home.x}, {seat.home.y}, {seat
                                                .home.z}
                                        </p>
                                    {:else}
                                        <span
                                            class="text-muted-foreground"
                                            data-test="seat-home-{seat.id}"
                                        >
                                            —
                                        </span>
                                    {/if}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        {#if seat.is_active}
                                            <Form
                                                {...GameSeatController.retire.form(
                                                    {
                                                        game: game.id,
                                                        seat: seat.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                        data-test="retire-seat-{seat.id}"
                                                    >
                                                        <UserMinus
                                                            class="h-4 w-4"
                                                        />
                                                        Retire
                                                        <span class="sr-only">
                                                            {seat.user_name}'s
                                                            seat
                                                        </span>
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        {:else}
                                            <Form
                                                {...GameSeatController.reactivate.form(
                                                    {
                                                        game: game.id,
                                                        seat: seat.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                        data-test="reactivate-seat-{seat.id}"
                                                    >
                                                        <RotateCcw
                                                            class="h-4 w-4"
                                                        />
                                                        Reactivate
                                                        <span class="sr-only">
                                                            {seat.user_name}'s
                                                            seat
                                                        </span>
                                                    </Button>
                                                {/snippet}
                                            </Form>
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
