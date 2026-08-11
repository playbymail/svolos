<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import { Button } from '@/components/ui/button';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import type { AdminUser, UserRoleOption } from '@/types';

    let {
        user,
        roles,
    }: {
        user: AdminUser;
        roles: UserRoleOption[];
    } = $props();

    /*
     * A **writable** `$derived`: the picker assigns to it while the administrator is choosing, and it
     * snaps back to the server's value whenever `user.role` changes. That is what makes a refused or
     * failed change unable to leave the picker showing a role the account does not hold — there is no
     * second copy of the truth to go stale, which a `$state` seeded once would be.
     *
     * Held as `string` because that is what `Select` writes back, so `bind:` needs no cast.
     */
    let selectedRole = $derived<string>(user.role);

    const selectedRoleLabel = $derived(
        roles.find((option) => option.value === selectedRole)?.label ??
            'Choose a role',
    );
</script>

<Form
    {...UserController.updateRole.form(user.id)}
    options={{ preserveScroll: true }}
    class="flex items-center gap-2"
>
    {#snippet children({ processing })}
        <Select type="single" name="role" bind:value={selectedRole}>
            <SelectTrigger class="w-40" aria-label="Role for {user.name}">
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
            disabled={processing || selectedRole === user.role}
            data-test="save-role-{user.id}"
        >
            Save
        </Button>
    {/snippet}
</Form>
