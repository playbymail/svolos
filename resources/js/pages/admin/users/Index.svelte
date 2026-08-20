<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as usersIndex } from '@/routes/admin/users';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Accounts',
                href: usersIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import BadgeCheck from '@lucide/svelte/icons/badge-check';
    import KeyRound from '@lucide/svelte/icons/key-round';
    import MailWarning from '@lucide/svelte/icons/mail-warning';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import UserRoundCog from '@lucide/svelte/icons/user-round-cog';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import ImpersonationController from '@/actions/App/Http/Controllers/ImpersonationController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
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
    import UserRoleForm from '@/components/UserRoleForm.svelte';
    import type { AdminUser, UserRoleOption } from '@/types';

    let {
        users,
        roles,
    }: {
        users: AdminUser[];
        roles: UserRoleOption[];
    } = $props();

    /*
     * The role picker lives in its own component per row so each one can hold the administrator's
     * in-progress choice in a writable `$derived` off that row's `user.role`. A map of choices kept
     * on this page would have to be a `$state` re-seeded from an `$effect`, which is a second copy
     * of the truth that goes stale the moment a change is refused.
     */
</script>

<AppHead title="Accounts" />

<h1 class="sr-only">Accounts</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Accounts"
        description="Every account in this installation, how it signs in, and how many browsers it is currently signed in on."
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border bg-muted/40">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Account</th>
                    <th scope="col" class="px-4 py-3 font-medium">Sign-in</th>
                    <th scope="col" class="px-4 py-3 font-medium">Sessions</th>
                    <th scope="col" class="px-4 py-3 font-medium">Created</th>
                    <th scope="col" class="px-4 py-3 font-medium">Role</th>
                    <th scope="col" class="px-4 py-3 text-right">
                        <span class="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {#each users as user (user.id)}
                    <tr class="border-b border-border last:border-b-0">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{user.name}</span>
                                {#if user.is_self}
                                    <Badge variant="outline">You</Badge>
                                {/if}
                            </div>
                            <p class="text-muted-foreground">{user.email}</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                {#if user.email_verified}
                                    <Badge variant="secondary">
                                        <BadgeCheck aria-hidden="true" />
                                        Verified
                                    </Badge>
                                {:else}
                                    <Badge variant="destructive">
                                        <MailWarning aria-hidden="true" />
                                        Unverified
                                    </Badge>
                                {/if}
                                {#if user.two_factor_enabled}
                                    <Badge variant="secondary">
                                        <KeyRound aria-hidden="true" />
                                        Two-factor
                                    </Badge>
                                {/if}
                            </div>
                        </td>
                        <td
                            class="px-4 py-3 text-muted-foreground"
                            data-test="user-sessions-count-{user.id}"
                        >
                            {user.sessions_count}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span title={user.created_at}>
                                {user.created_at_diff ?? '—'}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            {#if user.is_self}
                                <span class="text-muted-foreground">
                                    {user.role_label}
                                </span>
                            {:else}
                                <UserRoleForm {user} {roles} />
                            {/if}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end">
                                {#if user.can_impersonate}
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            {#snippet children(props)}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onclick={props.onClick}
                                                    data-test="impersonate-user-{user.id}"
                                                >
                                                    <UserRoundCog
                                                        class="h-4 w-4"
                                                    />
                                                    <span class="sr-only">
                                                        Sign in as {user.name}
                                                    </span>
                                                </Button>
                                            {/snippet}
                                        </DialogTrigger>

                                        <DialogContent>
                                            <Form
                                                {...ImpersonationController.store.form(
                                                    user.id,
                                                )}
                                                class="space-y-6"
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <DialogTitle>
                                                        Sign in as this account?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        You will see the
                                                        application exactly as
                                                        {user.name}
                                                        ({user.email}) does, and
                                                        anything you do will be
                                                        done as them. This
                                                        administration area is
                                                        closed while you are
                                                        signed in as somebody
                                                        else — a banner at the
                                                        bottom of every screen
                                                        brings you back.
                                                    </DialogDescription>

                                                    <DialogFooter class="gap-2">
                                                        <DialogClose asChild>
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
                                                            disabled={processing}
                                                            data-test="confirm-impersonate-user-{user.id}"
                                                        >
                                                            Sign in as {user.name}
                                                        </Button>
                                                    </DialogFooter>
                                                {/snippet}
                                            </Form>
                                        </DialogContent>
                                    </Dialog>
                                {/if}

                                {#if !user.is_self}
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            {#snippet children(props)}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                    onclick={props.onClick}
                                                    data-test="delete-user-{user.id}"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                    <span class="sr-only">
                                                        Delete {user.name}'s
                                                        account
                                                    </span>
                                                </Button>
                                            {/snippet}
                                        </DialogTrigger>

                                        <DialogContent>
                                            <Form
                                                {...UserController.destroy.form(
                                                    user.id,
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
                                                        Delete this account?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        {user.name}
                                                        ({user.email}) will be
                                                        removed, along with
                                                        every browser they are
                                                        signed in on and every
                                                        passkey they registered.
                                                        This cannot be undone.
                                                    </DialogDescription>

                                                    <DialogFooter class="gap-2">
                                                        <DialogClose asChild>
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
                                                            data-test="confirm-delete-user-{user.id}"
                                                        >
                                                            Delete account
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
</div>
