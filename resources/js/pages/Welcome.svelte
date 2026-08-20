<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ArrowRight from '@lucide/svelte/icons/arrow-right';
    import Layers from '@lucide/svelte/icons/layers';
    import Users from '@lucide/svelte/icons/users';
    import Workflow from '@lucide/svelte/icons/workflow';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardDescription,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { toUrl } from '@/lib/utils';
    import { dashboard, docs, login, story } from '@/routes';

    const user = $derived(page.props.auth.user);

    const openingLines = [
        'The klaxons wake you.',
        'The chronometers say you have been asleep for decades.',
        'The voyage was supposed to take months.',
    ];

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
        class="mt-6 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
    >
        Your colony ship has finally reached its destination—but decades late,
        crippled, and dying.
    </h1>

    <div class="mt-6 space-y-5 text-lg leading-8 text-muted-foreground">
        <p>
            The engines are burned out, critical systems are failing, and the
            technology that carried you across the stars can no longer be
            maintained. Thousands of colonists are awake, frightened, and
            depending on you to get them safely to the surface.
        </p>

        <p>
            The world below should have been ready. Advance expeditions
            established mines, farms, factories, fuel stores, and supply depots
            before your arrival. Their work remains. They do not. No
            transmissions. No ships. No sign of the people who were supposed to
            greet you. You have inherited their resources, their silence, and
            whatever happened to them.
        </p>

        <p>
            Now the colony is yours to command. Decide what to build, what to
            mine, what to salvage, and what to sacrifice. Rebuild from the
            wreckage, explore the unknown, and lead your people from survival
            toward a new civilization among the stars.
        </p>
    </div>

    <!--
        The copy bolds this last sentence: it is the call to action, so it is set as one and put
        against the buttons rather than left inside the paragraph above it.
    -->
    <p class="mt-6 text-xl font-medium tracking-tight text-balance sm:text-2xl">
        Take command of your colony and begin the challenge.
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
                <Link href={toUrl(story())} class={props.class}>
                    Read the story
                </Link>
            {/snippet}
        </Button>

        <Button variant="ghost" asChild>
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

<!--
    The way into the backstory. A first-time visitor has nowhere else to go — there is no sign-up —
    so the introduction is quoted here in the words it opens with, rather than named in a nav item
    and hoped for.
-->
<section
    class="mt-14 max-w-3xl rounded-xl border border-border bg-muted/40 p-6 sm:mt-16 sm:p-8"
>
    <span
        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
    >
        Player introduction
    </span>

    <div class="mt-4 space-y-2">
        {#each openingLines as line (line)}
            <p class="text-xl font-medium tracking-tight text-balance">
                {line}
            </p>
        {/each}
    </div>

    <p class="mt-4 text-muted-foreground">
        They prepared a world for you. And then they vanished.
    </p>

    <Link
        href={toUrl(story())}
        class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium underline-offset-4 hover:underline"
    >
        Read the story
        <ArrowRight class="size-4" aria-hidden="true" />
    </Link>
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
