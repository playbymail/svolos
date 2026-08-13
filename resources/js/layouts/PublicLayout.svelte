<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Button } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { dashboard, docs, home, login, story } from '@/routes';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const name = $derived(page.props.name);
    const user = $derived(page.props.auth.user);
</script>

<div class="flex min-h-svh flex-col bg-background text-foreground">
    <header
        class="sticky top-0 z-10 border-b border-border/60 bg-background/85 backdrop-blur"
    >
        <div
            class="mx-auto flex h-16 w-full max-w-5xl items-center justify-between gap-4 px-6"
        >
            <Link
                href={toUrl(home())}
                class="flex items-center gap-2.5 transition-opacity hover:opacity-80"
            >
                <AppLogoIcon class="size-6 fill-current text-foreground" />
                <span class="text-sm font-semibold tracking-tight">{name}</span>
            </Link>

            <nav class="flex items-center gap-1" aria-label="Main">
                <Button variant="ghost" size="sm" asChild>
                    {#snippet children(props)}
                        <Link href={toUrl(story())} class={props.class}>
                            The story
                        </Link>
                    {/snippet}
                </Button>

                <Button variant="ghost" size="sm" asChild>
                    {#snippet children(props)}
                        <Link href={toUrl(docs())} class={props.class}>
                            Docs
                        </Link>
                    {/snippet}
                </Button>

                {#if user}
                    <Button size="sm" asChild>
                        {#snippet children(props)}
                            <Link href={toUrl(dashboard())} class={props.class}>
                                Dashboard
                            </Link>
                        {/snippet}
                    </Button>
                {:else}
                    <Button size="sm" asChild>
                        {#snippet children(props)}
                            <Link href={toUrl(login())} class={props.class}>
                                Log in
                            </Link>
                        {/snippet}
                    </Button>
                {/if}
            </nav>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-6 py-16 sm:py-20">
        {@render children?.()}
    </main>

    <footer class="border-t border-border/60">
        <div
            class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-6 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <p>{name} — access is by invitation only.</p>

            <div class="flex items-center gap-4">
                <Link
                    href={toUrl(story())}
                    class="underline-offset-4 transition-colors hover:text-foreground hover:underline"
                >
                    The story
                </Link>
                <Link
                    href={toUrl(docs())}
                    class="underline-offset-4 transition-colors hover:text-foreground hover:underline"
                >
                    Documentation
                </Link>
            </div>
        </div>
    </footer>
</div>
