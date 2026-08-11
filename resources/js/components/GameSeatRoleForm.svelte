<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import GameSeatController from '@/actions/App/Http/Controllers/Admin/GameSeatController';
    import { Button } from '@/components/ui/button';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import type { AdminGame, AdminGameSeat, GameRoleOption } from '@/types';

    let {
        game,
        seat,
        roles,
    }: {
        game: AdminGame;
        seat: AdminGameSeat;
        roles: GameRoleOption[];
    } = $props();

    /*
     * A **writable** `$derived`, exactly as `UserRoleForm.svelte` does it: the picker assigns to it
     * while the administrator is choosing, and it snaps back to the server's value whenever
     * `seat.role` changes, so a refused or failed change cannot leave a picker showing a role the
     * seat does not hold.
     *
     * This is why the picker is its own component rather than inline on the roster: a map of
     * in-progress choices keyed by seat would have to be `$state` re-seeded from an `$effect` — a
     * second copy of the truth — which `eslint-plugin-svelte`'s `prefer-writable-derived` rejects.
     *
     * Held as `string` because that is what `Select` writes back, so `bind:` needs no cast.
     */
    let selectedRole = $derived<string>(seat.role);

    const selectedRoleLabel = $derived(
        roles.find((option) => option.value === selectedRole)?.label ??
            'Choose a role',
    );
</script>

<Form
    {...GameSeatController.updateRole.form({ game: game.id, seat: seat.id })}
    options={{ preserveScroll: true }}
    class="flex items-center gap-2"
>
    {#snippet children({ processing })}
        <Select type="single" name="role" bind:value={selectedRole}>
            <SelectTrigger
                class="w-40"
                aria-label="Game role for {seat.user_name}"
            >
                {selectedRoleLabel}
            </SelectTrigger>
            <SelectContent>
                {#each roles as option (option.value)}
                    <SelectItem value={option.value} label={option.label} />
                {/each}
            </SelectContent>
        </Select>
        <Button
            type="submit"
            variant="secondary"
            size="sm"
            disabled={processing || selectedRole === seat.role}
            data-test="save-seat-role-{seat.id}"
        >
            Save
        </Button>
    {/snippet}
</Form>
