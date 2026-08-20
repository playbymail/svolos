<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import BotMessageSquare from '@lucide/svelte/icons/bot-message-square';
    import ChevronRight from '@lucide/svelte/icons/chevron-right';
    import Dices from '@lucide/svelte/icons/dices';
    import MailPlus from '@lucide/svelte/icons/mail-plus';
    import MonitorSmartphone from '@lucide/svelte/icons/monitor-smartphone';
    import Users from '@lucide/svelte/icons/users';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { toUrl } from '@/lib/utils';
    import { index as agentsIndex } from '@/routes/admin/agents';
    import { index as gamesIndex } from '@/routes/admin/games';
    import { index as invitationsIndex } from '@/routes/admin/invitations';
    import { index as sessionsIndex } from '@/routes/admin/sessions';
    import { index as usersIndex } from '@/routes/admin/users';
</script>

<AppHead title="Administration" />

<h1 class="sr-only">Administration</h1>

<div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
    <Heading
        title="Administration"
        description="Everything here is restricted to administrators."
    />

    <nav class="grid gap-3 sm:grid-cols-2" aria-label="Administration">
        <Link
            href={toUrl(invitationsIndex())}
            class="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-accent"
        >
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                aria-hidden="true"
            >
                <MailPlus class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-0.5">
                <p class="font-medium tracking-tight">Invitations</p>
                <p class="text-sm text-muted-foreground">
                    Invite people, choose their role, and revoke links that
                    should no longer work.
                </p>
            </div>
            <ChevronRight
                class="ms-auto h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>

        <Link
            href={toUrl(usersIndex())}
            class="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-accent"
        >
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                aria-hidden="true"
            >
                <Users class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-0.5">
                <p class="font-medium tracking-tight">Accounts</p>
                <p class="text-sm text-muted-foreground">
                    See every account, change the role one holds, and delete
                    accounts that should no longer exist.
                </p>
            </div>
            <ChevronRight
                class="ms-auto h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>

        <Link
            href={toUrl(gamesIndex())}
            class="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-accent"
        >
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                aria-hidden="true"
            >
                <Dices class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-0.5">
                <p class="font-medium tracking-tight">Games</p>
                <p class="text-sm text-muted-foreground">
                    Create games and manage who sits at each one. A game role
                    applies to that game only.
                </p>
            </div>
            <ChevronRight
                class="ms-auto h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>

        <Link
            href={toUrl(agentsIndex())}
            class="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-accent"
        >
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                aria-hidden="true"
            >
                <BotMessageSquare class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-0.5">
                <p class="font-medium tracking-tight">Agents</p>
                <p class="text-sm text-muted-foreground">
                    Create accounts that play by themselves, and issue the
                    tokens they sign in with.
                </p>
            </div>
            <ChevronRight
                class="ms-auto h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>

        <Link
            href={toUrl(sessionsIndex())}
            class="flex items-center gap-4 rounded-lg border border-border p-4 transition-colors hover:bg-accent"
        >
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                aria-hidden="true"
            >
                <MonitorSmartphone class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-0.5">
                <p class="font-medium tracking-tight">Sessions</p>
                <p class="text-sm text-muted-foreground">
                    See which browsers are signed in, and sign any of them out.
                </p>
            </div>
            <ChevronRight
                class="ms-auto h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </Link>
    </nav>
</div>
