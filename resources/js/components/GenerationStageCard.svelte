<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Dices from 'lucide-svelte/icons/dices';
    import Lock from 'lucide-svelte/icons/lock';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import type { GenerationStageSummary } from '@/types';
    import type { RouteFormDefinition } from '@/wayfinder';

    /*
     * One stage of a game's generation. The two form endpoints are props rather than imports so the
     * card can be rendered without them — that is the administrator's copy of this screen, which shows
     * exactly the same facts and offers no controls, because running the generators is the
     * gamemaster's. `canGenerate` is the game still being in setup.
     */
    let {
        stage,
        generateAction,
        acceptAction,
        canGenerate = false,
    }: {
        stage: GenerationStageSummary;
        generateAction?: RouteFormDefinition<'post'>;
        acceptAction?: RouteFormDefinition<'post'>;
        canGenerate?: boolean;
    } = $props();

    const stateVariants: Record<
        GenerationStageSummary['state'],
        'default' | 'secondary' | 'outline'
    > = {
        locked: 'secondary',
        ready: 'outline',
        review: 'default',
        accepted: 'secondary',
    };

    /*
     * Whether to offer the seed box and the generate button. `review` gets it too: regenerating is the
     * same endpoint with a different seed, which is why there is one form rather than two.
     */
    const canRun = $derived(
        canGenerate &&
            generateAction !== undefined &&
            (stage.state === 'ready' || stage.state === 'review'),
    );

    const canAccept = $derived(
        canGenerate && acceptAction !== undefined && stage.state === 'review',
    );

    /**
     * Flatten a generator's summary into label/value pairs.
     *
     * The summary is whatever that stage's generator recorded, so this renders what it finds rather
     * than a fixed shape. The star mix is the one nested value: it arrives keyed by how many stars a
     * stellium has, and reads as "70 × 1 star" rather than as an object.
     */
    function summaryEntries(
        summary: Record<string, unknown> | null,
    ): { label: string; value: string }[] {
        if (summary === null) {
            return [];
        }

        return Object.entries(summary).flatMap(([key, value]) => {
            if (key === 'mix' && value !== null && typeof value === 'object') {
                return Object.entries(value as Record<string, unknown>).map(
                    ([stars, count]) => ({
                        label: `${stars} star${stars === '1' ? '' : 's'}`,
                        value: String(count),
                    }),
                );
            }

            return [{ label: key.replaceAll('_', ' '), value: String(value) }];
        });
    }

    const entries = $derived(summaryEntries(stage.summary));
</script>

<section
    class="space-y-4 rounded-lg border border-border p-4"
    data-test="generation-stage-{stage.stage}"
>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 class="font-medium">{stage.label}</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {stage.description}
            </p>
        </div>
        <Badge
            variant={stateVariants[stage.state]}
            data-test="generation-state-{stage.stage}"
        >
            {#if stage.state === 'locked'}
                <Lock class="h-3 w-3" />
            {:else if stage.state === 'accepted'}
                <Check class="h-3 w-3" />
            {/if}
            {stage.state_label}
        </Badge>
    </div>

    {#if stage.seed !== null}
        <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-muted-foreground">Seed</dt>
                <dd>
                    <code
                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                        data-test="generation-seed-{stage.stage}"
                    >
                        {stage.seed}
                    </code>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Attempt</dt>
                <dd>{stage.attempt}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Generated</dt>
                <dd>{stage.generated_at_diff ?? '—'}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Accepted</dt>
                <dd>{stage.accepted_at_diff ?? 'Not yet'}</dd>
            </div>
        </dl>
    {/if}

    {#if entries.length > 0}
        <ul class="flex flex-wrap gap-2 text-xs">
            {#each entries as entry (entry.label)}
                <li class="rounded border border-border px-2 py-1">
                    <span class="text-muted-foreground">{entry.label}</span>
                    <span class="ml-1 font-medium">{entry.value}</span>
                </li>
            {/each}
        </ul>
    {/if}

    {#if stage.state === 'locked'}
        <p class="text-sm text-muted-foreground">
            Accept the stage before this one first — each stage is generated on
            top of the last.
        </p>
    {/if}

    {#if canAccept && acceptAction}
        <!--
            Accepting is what unlocks the next stage, and it cannot be undone stage by stage: the
            only way back past it is starting the whole generation over.
        -->
        <Form {...acceptAction} options={{ preserveScroll: true }}>
            {#snippet children({ processing })}
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="accept-generation-{stage.stage}"
                >
                    {#if processing}
                        <Spinner />
                    {:else}
                        <Check class="h-4 w-4" />
                    {/if}
                    Accept {stage.label.toLowerCase()}
                </Button>
            {/snippet}
        </Form>
    {/if}

    {#if canRun && generateAction}
        <Form
            {...generateAction}
            options={{ preserveScroll: true }}
            class="grid gap-4 border-t border-border pt-4 sm:grid-cols-[minmax(0,1fr)_auto]"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="seed-{stage.stage}">Seed</Label>
                    <Input
                        id="seed-{stage.stage}"
                        type="number"
                        name="seed"
                        required
                        min={0}
                        max={4294967295}
                        step={1}
                        inputmode="numeric"
                        autocomplete="off"
                        value={stage.suggested_seed}
                        data-test="generation-seed-input-{stage.stage}"
                    />
                    <p class="text-xs text-muted-foreground">
                        {stage.state === 'review'
                            ? 'Generating again replaces what is here. It has to be a different seed — the same one would draw the same thing.'
                            : 'The same seed always produces the same result, which is what makes a game reproducible.'}
                    </p>
                    <InputError message={errors.seed} />
                </div>

                <div class="flex items-end">
                    <Button
                        type="submit"
                        variant={stage.state === 'review'
                            ? 'secondary'
                            : 'default'}
                        disabled={processing}
                        data-test="generate-{stage.stage}"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <Dices class="h-4 w-4" />
                        {/if}
                        {stage.state === 'review'
                            ? 'Try another seed'
                            : 'Generate'}
                    </Button>
                </div>
            {/snippet}
        </Form>
    {/if}

    {#if stage.history.length > 0}
        <!--
            The seeds that were tried and dropped. They produced nothing that still exists, so the
            number is all that is left of them — and it is the thing worth keeping.
        -->
        <details class="text-sm" data-test="generation-history-{stage.stage}">
            <summary class="cursor-pointer text-muted-foreground">
                {stage.history.length} earlier
                {stage.history.length === 1 ? 'attempt' : 'attempts'}
            </summary>
            <ul class="mt-2 space-y-1">
                {#each stage.history as attempt (attempt.attempt)}
                    <li class="text-muted-foreground">
                        Attempt {attempt.attempt} · seed
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">
                            {attempt.seed}
                        </code>
                        · {attempt.generated_at_diff ?? '—'}
                    </li>
                {/each}
            </ul>
        </details>
    {/if}
</section>
