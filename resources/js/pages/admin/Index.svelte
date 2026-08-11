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
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import MailPlus from 'lucide-svelte/icons/mail-plus';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { toUrl } from '@/lib/utils';
    import { index as invitationsIndex } from '@/routes/admin/invitations';
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
    </nav>
</div>
