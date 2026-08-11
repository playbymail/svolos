<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import UserRoundCog from 'lucide-svelte/icons/user-round-cog';
    import ImpersonationController from '@/actions/App/Http/Controllers/ImpersonationController';
    import { Button } from '@/components/ui/button';

    /*
     * Mounted once at the app root by `resources/js/lib/impersonation-banner.ts`, outside Inertia's
     * `#app` element, so this reads the shared props straight off the `page` state rather than
     * taking them as props from a layout. `page.props` starts life as `{}` and is filled in when
     * `createInertiaApp()` resolves, which is why `auth` is reached through `?.` — for the first
     * tick there is nothing there.
     */
    const impersonator = $derived(page.props.auth?.impersonator ?? null);
    const impersonated = $derived(page.props.auth?.user ?? null);
</script>

{#if impersonator}
    <section
        aria-label="Impersonation"
        data-test="impersonation-banner"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-destructive bg-destructive text-destructive-foreground"
    >
        <div
            class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-2.5 text-sm"
        >
            <p class="flex items-center gap-2">
                <UserRoundCog class="size-4 shrink-0" aria-hidden="true" />
                <span>
                    You are signed in as
                    <strong>{impersonated?.name}</strong>. Your own account is
                    {impersonator.name} ({impersonator.email}).
                </span>
            </p>

            <Form {...ImpersonationController.destroy.form()}>
                {#snippet children({ processing }: { processing: boolean })}
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        disabled={processing}
                        data-test="stop-impersonating"
                    >
                        Back to my account
                    </Button>
                {/snippet}
            </Form>
        </div>
    </section>
{/if}
