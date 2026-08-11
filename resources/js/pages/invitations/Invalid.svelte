<script module lang="ts">
    /**
     * The heading and description come from the server, because which of the three problems applies
     * is a server-side decision (see `App\Enums\InvitationLinkProblem`).
     *
     * The layout export is a *function* here rather than the usual object: the Svelte adapter calls
     * it with the page props and spreads the result into the layout, which is how a page feeds
     * dynamic values to a layout it does not import. AuthLayout renders `title` and `description`.
     */
    export const layout = (props: {
        title: string;
        description: string;
    }): { title: string; description: string } => ({
        title: props.title,
        description: props.description,
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import CircleHelp from 'lucide-svelte/icons/circle-help';
    import Clock from 'lucide-svelte/icons/clock';
    import UserCheck from 'lucide-svelte/icons/user-check';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { home, login } from '@/routes';
    import type { InvitationLinkProblem } from '@/types';

    let {
        reason,
        title,
    }: {
        reason: InvitationLinkProblem;
        title: string;
        description: string;
    } = $props();

    const icons = {
        unknown: CircleHelp,
        expired: Clock,
        accepted: UserCheck,
    };

    const Icon = $derived(icons[reason]);
</script>

<AppHead {title} />

<div
    class="flex flex-col items-center gap-6"
    data-test="invitation-problem-{reason}"
>
    <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted"
        aria-hidden="true"
    >
        <Icon class="h-6 w-6 text-muted-foreground" />
    </div>

    {#if reason === 'accepted'}
        <Button class="w-full" asChild>
            {#snippet children(props)}
                <Link href={toUrl(login())} class={props.class}>
                    Log in instead
                </Link>
            {/snippet}
        </Button>
    {:else}
        <Button variant="secondary" class="w-full" asChild>
            {#snippet children(props)}
                <Link href={toUrl(home())} class={props.class}>
                    Back to the home page
                </Link>
            {/snippet}
        </Button>
    {/if}
</div>
