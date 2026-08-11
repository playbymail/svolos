<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { index as sessionsIndex } from '@/routes/admin/sessions';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Sessions',
                href: sessionsIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import LogOut from 'lucide-svelte/icons/log-out';
    import Monitor from 'lucide-svelte/icons/monitor';
    import SessionController from '@/actions/App/Http/Controllers/Admin/SessionController';
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
    import type { AdminSession } from '@/types';

    let {
        sessions,
    }: {
        sessions: AdminSession[];
    } = $props();

    const otherSessionCount = $derived(
        sessions.filter((session) => !session.is_current).length,
    );
</script>

<AppHead title="Sessions" />

<h1 class="sr-only">Sessions</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Sessions"
        description="Every browser currently signed in to this installation. Signing one out ends it immediately — whoever is using it has to log in again."
    />

    {#if sessions.length === 0}
        <div class="rounded-lg border border-border p-8 text-center">
            <p class="font-medium">No signed-in sessions</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Nobody is signed in right now.
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
                            Browser
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            IP address
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Last active
                        </th>
                        <th scope="col" class="px-4 py-3 text-right">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <!--
                        Keyed by `digest`, which is the only identifier the server sends. The session
                        id itself never reaches this page: it is the value in that browser's session
                        cookie, so anything holding it could impersonate the browser. Do not add an
                        `id` to the props to key this loop with.
                    -->
                    {#each sessions as session (session.digest)}
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">
                                        {session.user_name ?? 'Unknown'}
                                    </span>
                                    {#if session.is_current}
                                        <Badge
                                            variant="outline"
                                            data-test="current-session"
                                        >
                                            This browser
                                        </Badge>
                                    {/if}
                                </div>
                                <p class="text-muted-foreground">
                                    {session.user_email ?? '—'}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Monitor
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        {session.browser} on {session.platform}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {session.ip_address ?? '—'}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <span title={session.last_active_at}>
                                    {session.last_active_at_diff}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end">
                                    {#if !session.is_current}
                                        <Form
                                            {...SessionController.destroy.form()}
                                            options={{ preserveScroll: true }}
                                        >
                                            {#snippet children({ processing })}
                                                <!--
                                                    The digest travels in the request body, never in
                                                    the URL: a URL is written to history, logs and
                                                    referrers, and this value addresses a live
                                                    session.
                                                -->
                                                <input
                                                    type="hidden"
                                                    name="digest"
                                                    value={session.digest}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                    disabled={processing}
                                                    data-test="sign-out-session-{session.digest}"
                                                >
                                                    <LogOut class="h-4 w-4" />
                                                    <span class="sr-only">
                                                        Sign out {session.user_name ??
                                                            'this session'} on {session.browser}
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

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Sign out everybody else"
                description="Ends every session in this installation except the one you are reading this in."
            />

            <Dialog>
                <DialogTrigger asChild>
                    {#snippet children(props)}
                        <Button
                            variant="destructive"
                            disabled={otherSessionCount === 0}
                            onclick={props.onClick}
                            data-test="sign-out-other-sessions"
                        >
                            <LogOut class="h-4 w-4" />
                            Sign out all other sessions
                        </Button>
                    {/snippet}
                </DialogTrigger>

                <DialogContent>
                    <Form
                        {...SessionController.destroyOthers.form()}
                        class="space-y-6"
                        options={{ preserveScroll: true }}
                    >
                        {#snippet children({ processing })}
                            <DialogTitle
                                >Sign out all other sessions?</DialogTitle
                            >
                            <DialogDescription>
                                {otherSessionCount === 1
                                    ? 'One other session will be ended'
                                    : `${otherSessionCount} other sessions will be ended`}
                                immediately, and everybody using them will have to
                                log in again. This browser stays signed in.
                            </DialogDescription>

                            <DialogFooter class="gap-2">
                                <DialogClose asChild>
                                    {#snippet children(props)}
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
                                    data-test="confirm-sign-out-other-sessions"
                                >
                                    Sign them out
                                </Button>
                            </DialogFooter>
                        {/snippet}
                    </Form>
                </DialogContent>
            </Dialog>
        </section>
    {/if}
</div>
