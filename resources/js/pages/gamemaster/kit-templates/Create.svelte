<script module lang="ts">
    import { dashboard } from '@/routes';
    import {
        create,
        index as kitTemplatesIndex,
    } from '@/routes/gamemaster/kit-templates';

    export const layout = {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Kits', href: kitTemplatesIndex() },
            { title: 'New', href: create() },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Dices from '@lucide/svelte/icons/dices';
    import Upload from '@lucide/svelte/icons/upload';
    import KitTemplateController from '@/actions/App/Http/Controllers/Gamemaster/KitTemplateController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';

    let { suggestedSeed }: { suggestedSeed: number } = $props();

    /*
     * Which of the two ways to start a kit. Drawing is the default, unlike the home template stage
     * where uploading is: there, a game whose homes are decided in advance is the reason the stage
     * exists. Here the common case is starting from something and editing it, and a gamemaster with
     * a document already has somewhere obvious to put it.
     *
     * The inactive control is **disabled rather than hidden**, so the layout does not move under the
     * pointer while somebody makes up their mind — and a disabled input posts nothing, which is what
     * `exclude_unless` and `required_if` on the server expect.
     */
    let source = $state<'generate' | 'upload'>('generate');
</script>

<AppHead title="New kit" />

<h1 class="sr-only">New kit</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="New kit"
        description="Draw a kit from a seed and edit it, or upload a document you already have. Either way you can change every holding afterwards."
    />

    <Form
        {...KitTemplateController.store.form()}
        class="max-w-xl space-y-6 rounded-lg border border-border p-4"
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autocomplete="off"
                    placeholder="Lean start"
                    data-test="kit-name-input"
                />
                <p class="text-sm text-muted-foreground">
                    Yours to recognise. Nobody else sees your kits.
                </p>
                <InputError message={errors.name} />
            </div>

            <fieldset class="grid gap-3">
                <legend class="text-sm font-medium">Where it comes from</legend>

                <Label
                    for="source-generate"
                    class="flex items-start gap-3 font-normal"
                >
                    <input
                        id="source-generate"
                        type="radio"
                        name="source"
                        value="generate"
                        bind:group={source}
                        class="mt-1"
                        data-test="kit-source-generate"
                    />
                    <span>
                        <span class="block font-medium">Draw one</span>
                        <span class="block text-sm text-muted-foreground">
                            Starts from the standard opening and varies the
                            quantities. Which kinds, which inventories and which
                            technology levels never change.
                        </span>
                    </span>
                </Label>

                <Label
                    for="source-upload"
                    class="flex items-start gap-3 font-normal"
                >
                    <input
                        id="source-upload"
                        type="radio"
                        name="source"
                        value="upload"
                        bind:group={source}
                        class="mt-1"
                        data-test="kit-source-upload"
                    />
                    <span>
                        <span class="block font-medium">Upload a document</span>
                        <span class="block text-sm text-muted-foreground">
                            A kit you downloaded from here and edited, or wrote
                            yourself.
                        </span>
                    </span>
                </Label>

                <InputError message={errors.source} />
            </fieldset>

            <div class="grid gap-2">
                <Label
                    for="seed"
                    class={source === 'generate'
                        ? undefined
                        : 'text-muted-foreground'}>Seed</Label
                >
                <Input
                    id="seed"
                    name="seed"
                    type="number"
                    min="0"
                    value={suggestedSeed}
                    disabled={source !== 'generate'}
                    data-test="kit-seed-input"
                />
                <p class="text-sm text-muted-foreground">
                    The same seed always draws the same kit. It is kept with the
                    kit and travels inside the document when you download it.
                </p>
                <InputError message={errors.seed} />
            </div>

            <div class="grid gap-2">
                <Label
                    for="kit"
                    class={source === 'upload'
                        ? undefined
                        : 'text-muted-foreground'}>Kit document</Label
                >
                <Input
                    id="kit"
                    name="kit"
                    type="file"
                    accept="application/json,.json"
                    disabled={source !== 'upload'}
                    data-test="kit-document-input"
                />
                <InputError message={errors.kit} />
            </div>

            <Button
                type="submit"
                disabled={processing}
                data-test="store-kit-button"
            >
                {#if processing}
                    <Spinner />
                {:else if source === 'upload'}
                    <Upload class="h-4 w-4" aria-hidden="true" />
                {:else}
                    <Dices class="h-4 w-4" aria-hidden="true" />
                {/if}
                {source === 'upload' ? 'Upload kit' : 'Draw kit'}
            </Button>
        {/snippet}
    </Form>
</div>
