<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import Layers from 'lucide-svelte/icons/layers';
    import Users from 'lucide-svelte/icons/users';
    import Workflow from 'lucide-svelte/icons/workflow';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardDescription,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { toUrl } from '@/lib/utils';
    import { dashboard, docs, login } from '@/routes';

    const name = $derived(page.props.name);
    const user = $derived(page.props.auth.user);

    const capabilities = [
        {
            title: 'Game metadata',
            description:
                'Every game in play, with its name, description and lifecycle state recorded in one place instead of scattered across turn reports and mailing lists.',
            icon: Layers,
        },
        {
            title: 'The seat roster',
            description:
                'Who holds which seat, in which role, in which game. Seats outlive the people in them, so a hand-over mid-campaign leaves the record intact.',
            icon: Users,
        },
        {
            title: 'A clean engine boundary',
            description:
                'Turn processing, order resolution and map rendering stay in the game engine. This application answers who and what — never what happened.',
            icon: Workflow,
        },
    ];
</script>

<AppHead title="Welcome" />

<section class="max-w-3xl">
    <span
        class="inline-flex items-center rounded-full border border-border bg-muted px-3 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase"
    >
        Invite only
    </span>

    <h1
        class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
    >
        The roster behind the game.
    </h1>

    <p class="mt-5 text-lg text-muted-foreground">
        {name} keeps the record for a play-by-mail strategy game: the games themselves,
        the seats at each table, and the people holding them. The game engine takes
        the turns; this is the registry it plays against.
    </p>

    <div class="mt-8 flex flex-wrap items-center gap-3">
        {#if user}
            <Button asChild>
                {#snippet children(props)}
                    <Link href={toUrl(dashboard())} class={props.class}>
                        Go to your dashboard
                    </Link>
                {/snippet}
            </Button>
        {:else}
            <Button asChild>
                {#snippet children(props)}
                    <Link href={toUrl(login())} class={props.class}>
                        Log in
                    </Link>
                {/snippet}
            </Button>
        {/if}

        <Button variant="outline" asChild>
            {#snippet children(props)}
                <Link href={toUrl(docs())} class={props.class}>
                    Read the docs
                </Link>
            {/snippet}
        </Button>
    </div>

    {#if !user}
        <p class="mt-4 text-sm text-muted-foreground">
            Accounts come from invitations — there is no public sign-up. If you
            have been invited, the invitation carries its own link.
        </p>
    {/if}
</section>

<section class="mt-16 grid gap-4 sm:mt-20 md:grid-cols-3">
    {#each capabilities as capability (capability.title)}
        <Card>
            <CardHeader class="gap-3">
                <capability.icon
                    class="size-5 shrink-0 text-muted-foreground"
                />
                <CardTitle>{capability.title}</CardTitle>
                <CardDescription class="leading-relaxed">
                    {capability.description}
                </CardDescription>
            </CardHeader>
        </Card>
    {/each}
</section>
