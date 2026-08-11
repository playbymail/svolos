<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { home } from '@/routes';

    const plannedSections = [
        'Accepting an invitation and setting up your account',
        'Games: what the metadata means and who maintains it',
        'Seats and roles: joining a table, handing a seat over',
        'Where this application ends and the game engine begins',
    ];
</script>

<AppHead title="Documentation" />

<section class="max-w-3xl">
    <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
        Documentation
    </h1>

    <p class="mt-4 text-lg text-muted-foreground">
        Still being written. This page is the placeholder it will grow into.
    </p>

    <h2 class="mt-10 text-sm font-medium tracking-wide uppercase">
        Planned sections
    </h2>

    <ul class="mt-4 space-y-3 text-muted-foreground">
        {#each plannedSections as section (section)}
            <li class="flex gap-3">
                <span
                    class="mt-2.5 size-1.5 shrink-0 rounded-full bg-border"
                    aria-hidden="true"
                ></span>
                <span>{section}</span>
            </li>
        {/each}
    </ul>

    <div class="mt-10">
        <Button variant="outline" asChild>
            {#snippet children(props)}
                <Link href={toUrl(home())} class={props.class}>
                    Back to the start
                </Link>
            {/snippet}
        </Button>
    </div>
</section>
