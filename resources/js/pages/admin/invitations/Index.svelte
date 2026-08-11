<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as invitationsIndex } from '@/routes/admin/invitations';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Invitations',
                href: invitationsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import MailPlus from 'lucide-svelte/icons/mail-plus';
    import RefreshCw from 'lucide-svelte/icons/refresh-cw';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import InvitationController from '@/actions/App/Http/Controllers/Admin/InvitationController';
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
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import { Spinner } from '@/components/ui/spinner';
    import type {
        Invitation,
        InvitationRoleOption,
        InvitationStatus,
        UserRole,
    } from '@/types';

    let {
        invitations,
        roles,
        expiresAfterDays,
    }: {
        invitations: Invitation[];
        roles: InvitationRoleOption[];
        expiresAfterDays: number;
    } = $props();

    /*
     * `member` rather than `roles[0]`: the enum lists the administrator case first, and the default
     * on a form that grants privileges should be the smaller of the two whatever the order.
     */
    const defaultRole: UserRole = 'member';

    /* Bound to the role picker, which renders the hidden `role` input the form posts. */
    let role = $state<string>(defaultRole);

    const selectedRoleLabel = $derived(
        roles.find((option) => option.value === role)?.label ?? 'Choose a role',
    );

    const statusVariants: Record<
        InvitationStatus,
        'default' | 'secondary' | 'destructive'
    > = {
        pending: 'default',
        accepted: 'secondary',
        expired: 'destructive',
    };
</script>

<AppHead title="Invitations" />

<h1 class="sr-only">Invitations</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Invitations"
        description="Accounts are created by invitation only. Invite an email address, and whoever accepts it gets the role you choose here."
    />

    <section class="space-y-4">
        <Heading
            variant="small"
            title="Invite someone"
            description="They will be emailed a link that expires in {expiresAfterDays} days."
        />

        <Form
            {...InvitationController.store.form()}
            resetOnSuccess
            onSuccess={() => (role = defaultRole)}
            options={{ preserveScroll: true }}
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autocomplete="off"
                        placeholder="them@example.com"
                    />
                    <InputError message={errors.email} />
                </div>

                <div class="grid gap-2">
                    <Label for="role">Role</Label>
                    <Select type="single" name="role" bind:value={role}>
                        <SelectTrigger id="role" class="w-full">
                            {selectedRoleLabel}
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
                        disabled={processing}
                        data-test="send-invitation-button"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <MailPlus class="h-4 w-4" />
                        {/if}
                        Send invitation
                    </Button>
                </div>
            {/snippet}
        </Form>
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            title="All invitations"
            description="Accepted and expired invitations are kept, so you can see who was invited and who never arrived."
        />

        {#if invitations.length === 0}
            <div class="rounded-lg border border-border p-8 text-center">
                <p class="font-medium">No invitations yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Nobody has been invited. Send the first invitation above.
                </p>
            </div>
        {:else}
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Email address
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Role
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Invited by
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Expires
                            </th>
                            <th scope="col" class="px-4 py-3 text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each invitations as invitation (invitation.id)}
                            <tr class="border-b border-border last:border-b-0">
                                <td class="px-4 py-3 font-medium">
                                    {invitation.email}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {invitation.role_label}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        variant={statusVariants[
                                            invitation.status
                                        ]}
                                    >
                                        {invitation.status_label}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {invitation.invited_by ?? '—'}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {#if invitation.accepted_at_diff}
                                        Accepted {invitation.accepted_at_diff}
                                    {:else}
                                        <span title={invitation.expires_at}>
                                            {invitation.expires_at_diff}
                                        </span>
                                    {/if}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        {#if invitation.status !== 'accepted'}
                                            <Form
                                                {...InvitationController.resend.form(
                                                    invitation.id,
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
                                                        data-test="resend-invitation-{invitation.id}"
                                                    >
                                                        <RefreshCw
                                                            class="h-4 w-4"
                                                        />
                                                        <span class="sr-only">
                                                            Send a new link to {invitation.email}
                                                        </span>
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        {/if}

                                        <Dialog>
                                            <DialogTrigger asChild>
                                                {#snippet children(props)}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        onclick={props.onClick}
                                                        data-test="revoke-invitation-{invitation.id}"
                                                    >
                                                        <Trash2
                                                            class="h-4 w-4"
                                                        />
                                                        <span class="sr-only">
                                                            Revoke the
                                                            invitation for {invitation.email}
                                                        </span>
                                                    </Button>
                                                {/snippet}
                                            </DialogTrigger>

                                            <DialogContent>
                                                <Form
                                                    {...InvitationController.destroy.form(
                                                        invitation.id,
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
                                                            Revoke this
                                                            invitation?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            {#if invitation.status === 'accepted'}
                                                                {invitation.email}
                                                                has already used this
                                                                invitation. Revoking
                                                                it removes the record
                                                                only — their account
                                                                keeps working.
                                                            {:else}
                                                                The link sent to
                                                                {invitation.email}
                                                                will stop working
                                                                immediately, and they
                                                                will not be able to
                                                                create an account
                                                                unless you invite
                                                                them again.
                                                            {/if}
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
                                                                data-test="confirm-revoke-invitation-{invitation.id}"
                                                            >
                                                                Revoke
                                                                invitation
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
