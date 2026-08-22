<script module lang="ts">
    import { dashboard } from '@/routes';
    import {
        index as kitTemplatesIndex,
        show,
    } from '@/routes/gamemaster/kit-templates';
    import type { BreadcrumbItem, KitTemplateSummary } from '@/types';

    /*
     * A function rather than an object, because the last crumb is the kit's own name and only the
     * server knows it — see `.ai/rules/frontend.md`.
     */
    export const layout = (props: {
        kitTemplate: KitTemplateSummary;
    }): { breadcrumbs: BreadcrumbItem[] } => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Kits', href: kitTemplatesIndex() },
            {
                title: props.kitTemplate.name,
                href: show(props.kitTemplate.id),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Download from '@lucide/svelte/icons/download';
    import Save from '@lucide/svelte/icons/save';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import KitTemplateController from '@/actions/App/Http/Controllers/Gamemaster/KitTemplateController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import KitEditor from '@/components/KitEditor.svelte';
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
    import { Spinner } from '@/components/ui/spinner';
    import { toUrl } from '@/lib/utils';
    import { download } from '@/routes/gamemaster/kit-templates';
    import type { Kit, UnitCatalogue } from '@/types';

    /*
     * `KitTemplateSummary` is imported once, in the module block above. The two script blocks share
     * one module scope, so importing it again here is a duplicate-identifier error under
     * `svelte-check`.
     */
    let {
        kitTemplate,
        kit,
        catalogue,
    }: {
        kitTemplate: KitTemplateSummary;
        kit: Kit;
        catalogue: UnitCatalogue;
    } = $props();
</script>

<AppHead title={kitTemplate.name} />

<h1 class="sr-only">{kitTemplate.name}</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title={kitTemplate.name}
        description="What every player in a game using this kit begins holding. Quantities are yours to set; which kinds may sit in which inventory is the game's rule, so the pickers only offer legal choices."
    />

    <div class="flex flex-wrap items-center gap-3">
        {#if kitTemplate.file !== null}
            <Badge variant="outline">Read from {kitTemplate.file}</Badge>
        {/if}
        {#if kitTemplate.seed !== null}
            <Badge variant="outline">Seed {kitTemplate.seed}</Badge>
        {/if}
        {#each kit.entities as entity (entity.type)}
            <span class="text-sm text-muted-foreground">
                {entity.label}: {entity.mass} MU, {entity.volume} VU
            </span>
        {/each}
    </div>

    <div class="flex flex-wrap gap-3">
        <!--
            A plain anchor, not an Inertia `Link`: a `Link` issues an XHR visit, and an attachment
            response would go nowhere at all. `download` on a `Button` would be inert for the reason
            `href` on one is — see `.ai/rules/frontend.md` — so the anchor takes the button's classes
            through the snippet.
        -->
        <Button asChild variant="outline">
            {#snippet children(props)}
                <a
                    href={toUrl(download(kitTemplate.id)).toString()}
                    class={props.class}
                    data-test="download-kit-button"
                >
                    <Download class="h-4 w-4" aria-hidden="true" />
                    Download
                </a>
            {/snippet}
        </Button>

        <Dialog>
            <DialogTrigger asChild>
                {#snippet children(props)}
                    <Button
                        variant="ghost"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onclick={props.onClick}
                        data-test="delete-kit-button"
                    >
                        <Trash2 class="h-4 w-4" aria-hidden="true" />
                        Delete
                    </Button>
                {/snippet}
            </DialogTrigger>

            <DialogContent>
                <Form
                    {...KitTemplateController.destroy.form(kitTemplate.id)}
                    class="space-y-6"
                >
                    {#snippet children({ processing })}
                        <DialogTitle>Delete {kitTemplate.name}?</DialogTitle>
                        <!--
                            Worth saying out loud, because the opposite is what a reader assumes: a
                            run stores the kit it was given rather than a link to this row, so no
                            game can be disturbed by deleting one. Nothing else points at it either.
                        -->
                        <DialogDescription>
                            Games already generated with this kit keep exactly
                            what they were given — a run stores the kit itself,
                            not a link to this one. Nothing else points at it.
                            This cannot be undone.
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
                                data-test="confirm-delete-kit-button"
                            >
                                {#if processing}
                                    <Spinner />
                                {/if}
                                Delete kit
                            </Button>
                        </DialogFooter>
                    {/snippet}
                </Form>
            </DialogContent>
        </Dialog>
    </div>

    <Form
        {...KitTemplateController.update.form(kitTemplate.id)}
        options={{ preserveScroll: true }}
        class="space-y-6"
    >
        {#snippet children({ errors, processing })}
            <div class="grid max-w-xl gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autocomplete="off"
                    value={kitTemplate.name}
                    data-test="kit-name-input"
                />
                <InputError message={errors.name} />
            </div>

            <!--
                Whole-kit refusals — a holding repeated, a kind left out — land on `kit`, because they
                are facts about the document rather than about any one field. Per-field messages reach
                the editor through `errors`.
            -->
            <InputError message={errors.kit} />
            <InputError message={errors.entities} />

            <KitEditor {kit} {catalogue} {errors} />

            <Button
                type="submit"
                disabled={processing}
                data-test="save-kit-button"
            >
                {#if processing}
                    <Spinner />
                {:else}
                    <Save class="h-4 w-4" aria-hidden="true" />
                {/if}
                Save kit
            </Button>
        {/snippet}
    </Form>
</div>
