<script module lang="ts">
    import { dashboard } from '@/routes';
    import { show as gameShow } from '@/routes/gamemaster/games';
    import type { BreadcrumbItem, GamemasterGame } from '@/types';

    /**
     * The last crumb is the game's own name, which only the server knows, so the layout export is a
     * *function*: the Svelte adapter calls it with the page props and spreads the result into the
     * layout chosen in `app.ts`. The first crumb is the dashboard rather than an index of games —
     * there is no gamemaster games list, because the dashboard already is one.
     */
    export const layout = (props: {
        game: GamemasterGame;
    }): { breadcrumbs: BreadcrumbItem[] } => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
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
    import RotateCcw from 'lucide-svelte/icons/rotate-ccw';
    import Save from 'lucide-svelte/icons/save';
    import UserMinus from 'lucide-svelte/icons/user-minus';
    import UserPlus from 'lucide-svelte/icons/user-plus';
    import GameController from '@/actions/App/Http/Controllers/Gamemaster/GameController';
    import GameSeatController from '@/actions/App/Http/Controllers/Gamemaster/GameSeatController';
    import AppHead from '@/components/AppHead.svelte';
    import GameSeatRoleForm from '@/components/GameSeatRoleForm.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Label } from '@/components/ui/label';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import { Spinner } from '@/components/ui/spinner';
    /*
     * `GamemasterGame` is deliberately not re-imported here: the two `<script>` blocks share one
     * module scope, so the import in `<script module>` above is already visible and a second one is a
     * duplicate-identifier error under `svelte-check`.
     */
    import type {
        AssignableAccount,
        GameRole,
        GamemasterGameSeat,
        GameRoleOption,
        GameStatusOption,
    } from '@/types';

    let {
        game,
        seats,
        assignableAccounts,
        roles,
        statuses,
    }: {
        game: GamemasterGame;
        seats: GamemasterGameSeat[];
        assignableAccounts: AssignableAccount[];
        roles: GameRoleOption[];
        statuses: GameStatusOption[];
    } = $props();

    /*
     * The status picker. A writable `$derived` off the server's value, so a refused save snaps the
     * picker back rather than leaving it showing a status the game does not have.
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
            title="Status"
            description="Where the game is in its life. Nothing forces a game forward — a completed one can be reopened, and an archived one restored."
        />

        <Form
            {...GameController.update.form(game.id)}
            options={{ preserveScroll: true }}
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2"
        >
            {#snippet children({ errors, processing })}
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

                <!--
                    The name and short name are shown as text, never as inputs. A short name leaves
                    the application in turn reports and generated file names, so renaming one
                    relabels artefacts that already exist — it is the administrator's to change, and
                    `Gamemaster\GameStatusUpdateRequest` validates neither field, so a hand-made post
                    carrying them is dropped rather than written.
                -->
                <dl
                    class="grid gap-4 border-t border-border pt-4 text-sm sm:col-span-2 sm:grid-cols-2"
                    data-test="game-identity"
                >
                    <div>
                        <dt class="text-muted-foreground">Name</dt>
                        <dd class="font-medium">{game.name}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Short name</dt>
                        <dd>
                            <code class="rounded bg-muted px-1.5 py-0.5 text-xs"
                                >{game.short_name}</code
                            >
                        </dd>
                    </div>
                    <p class="text-muted-foreground sm:col-span-2">
                        Ask an administrator to change either of these.
                    </p>
                </dl>
            {/snippet}
        </Form>
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
                                    {#if seat.is_self}
                                        <span
                                            class="ml-1 text-muted-foreground"
                                            data-test="seat-self-{seat.id}"
                                        >
                                            (you)
                                        </span>
                                    {/if}
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
                                    <!--
                                        A gamemaster's seat is shown as a label rather than a
                                        picker. Handing the role out is allowed — that is the
                                        picker on a player's row — but taking it back is the
                                        administrator's, so there is nothing here to choose
                                        between. `updateRole()` refuses the demotion regardless of
                                        what this renders.
                                    -->
                                    {#if seat.can_demote}
                                        <GameSeatRoleForm
                                            action={GameSeatController.updateRole.form(
                                                {
                                                    game: game.id,
                                                    seat: seat.id,
                                                },
                                            )}
                                            {seat}
                                            {roles}
                                        />
                                    {:else}
                                        <span
                                            class="font-medium"
                                            data-test="seat-role-fixed-{seat.id}"
                                        >
                                            {seat.role_label}
                                        </span>
                                        <p class="text-muted-foreground">
                                            Only an administrator can change
                                            this.
                                        </p>
                                    {/if}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        {#if seat.can_retire}
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
                                        {:else if seat.is_active}
                                            <!--
                                                The only active seat without a retire control is
                                                your own: leaving a game you run is an
                                                administrator's doing, not a button you can press by
                                                accident.
                                            -->
                                            <span
                                                class="text-muted-foreground"
                                                data-test="cannot-retire-{seat.id}"
                                            >
                                                Ask an administrator to retire
                                                you
                                            </span>
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
