<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Dices from 'lucide-svelte/icons/dices';
    import Lock from 'lucide-svelte/icons/lock';
    import Upload from 'lucide-svelte/icons/upload';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { DEFAULT_MINIMUM_SEPARATION } from '@/types';
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
     * than a fixed shape. A nested value is spread into one entry per key, whatever the stage — the
     * planets stage's type breakdown reads as "rocky 341" with no help at all.
     *
     * The star mix is the one nested value that needs any: it arrives keyed by how many stars a
     * stellium has, so its keys are bare numbers and would read as "1 342" without the noun. That is
     * why `mix` is named here and nothing else is — a stage whose keys already say what they are
     * should not have to be added to this function.
     */
    function summaryEntries(
        summary: Record<string, unknown> | null,
    ): { key: string; label: string; value: string }[] {
        if (summary === null) {
            return [];
        }

        return Object.entries(summary).flatMap(([key, value]) => {
            if (value !== null && typeof value === 'object') {
                return Object.entries(value as Record<string, unknown>).map(
                    ([name, count]) => ({
                        key: `${key}.${name}`,
                        label:
                            key === 'mix'
                                ? `${name} star${name === '1' ? '' : 's'}`
                                : name.replaceAll('_', ' '),
                        value: String(count),
                    }),
                );
            }

            return [
                {
                    key,
                    label: key.replaceAll('_', ' '),
                    value: String(value),
                },
            ];
        });
    }

    const entries = $derived(summaryEntries(stage.summary));

    /*
     * Which unit the home stellia form's separation is counted in, held here because the label beside
     * the number has to change with it — a bare "5" means two different things and the reader must not
     * have to look at the checkbox to find out which.
     *
     * A **writable** `$derived` off the server's value, the same pattern the status picker uses: it
     * starts from the pending run's setting so trying again keeps the unit, and a refused submit snaps
     * it back rather than leaving the box showing a mode the run does not have.
     */
    let separationInHexes = $derived(stage.separation_in_hexes ?? false);

    const separationUnit = $derived(separationInHexes ? 'hexes' : 'distance');
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
            {#if stage.traveler}
                <!--
                    Shown only when it is on, because "Traveler no" would be noise on every ordinary
                    run and on the two stages that do not read the flag at all. What it changed is
                    visible in the summary beside it: occupied hexes equal to the location count.
                -->
                <div>
                    <dt class="text-muted-foreground">Traveler</dt>
                    <dd data-test="generation-traveler-{stage.stage}">
                        One system per hex
                    </dd>
                </div>
            {/if}
            {#if stage.stage === 'home_stellia'}
                <!--
                    Shown in **both** states, unlike the traveler flag above, because the numbers in
                    the summary beside it are meaningless without it: "minimum separation 5" is two
                    different arrangements depending on this, and the reader cannot tell which from
                    the number. A flag that is only worth showing when set is one thing; a unit is
                    never optional.
                -->
                <div>
                    <dt class="text-muted-foreground">Separation counted in</dt>
                    <dd data-test="generation-separation-unit-{stage.stage}">
                        {stage.separation_in_hexes
                            ? 'Hexes on the map'
                            : 'Distance through space'}
                    </dd>
                </div>
            {/if}
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
            <!--
                Keyed by the summary's own path rather than by the label: a stage carrying both a
                top-level `rocky` and a nested `types.rocky` would otherwise collide, and Svelte
                throws on duplicate keys rather than rendering one of them.
            -->
            {#each entries as entry (entry.key)}
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
                        <!--
                            The home stellia stage gets a sentence of its own because the usual one
                            is false there: its stream is seeded with the seed *and* the attempt, so
                            the same number really does produce a different arrangement, and the
                            "choose a different seed" rule is switched off for it on the server too.
                        -->
                        {#if stage.state !== 'review'}
                            The same seed always produces the same result, which
                            is what makes a game reproducible.
                        {:else if stage.stage === 'home_stellia'}
                            Generating again replaces what is here. The same
                            seed is fine — each attempt draws a different
                            arrangement from it.
                        {:else}
                            Generating again replaces what is here. It has to be
                            a different seed — the same one would draw the same
                            thing.
                        {/if}
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
                        <!--
                            "Try another seed" is the wrong label for the one stage where the seed
                            need not change: there, regenerating is asking for another arrangement of
                            the same world.
                        -->
                        {#if stage.state !== 'review'}
                            Generate
                        {:else if stage.stage === 'home_stellia'}
                            Try another arrangement
                        {:else}
                            Try another seed
                        {/if}
                    </Button>
                </div>

                {#if stage.stage === 'cluster'}
                    <!--
                        Only the cluster stage places coordinates, so it is the only one with this to
                        offer. The box starts from the pending run's own setting, so trying another
                        seed keeps the mode rather than quietly reverting to the ordinary draw.
                    -->
                    <div class="grid gap-2">
                        <Label
                            for="traveler-{stage.stage}"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox
                                id="traveler-{stage.stage}"
                                name="traveler"
                                checked={stage.traveler ?? false}
                                data-test="generation-traveler-input-{stage.stage}"
                            />
                            <span>Traveler mode</span>
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Give every system a hex of its own. Ordinarily a few
                            share one — same column, different height — and the
                            map stacks them.
                        </p>
                        <InputError message={errors.traveler} />
                    </div>
                {/if}

                {#if stage.stage === 'home_stellia'}
                    <!--
                        The home stellia stage's own input, and the counterpart of the traveler box
                        above: the only stage that reads it, starting from the pending run's own
                        setting so trying again keeps the value rather than reverting to the default.

                        It is also where the "no arrangement exists" message lands. That failure is a
                        rejected field rather than an error page precisely because this box is what
                        has to change — see `Gamemaster\GenerationController::store()`.
                    -->
                    <div class="grid gap-2">
                        <Label for="minimum-separation-{stage.stage}">
                            Minimum separation ({separationUnit})
                        </Label>
                        <Input
                            id="minimum-separation-{stage.stage}"
                            type="number"
                            name="minimum_separation"
                            min={1}
                            max={30}
                            step={1}
                            inputmode="numeric"
                            autocomplete="off"
                            class="sm:max-w-32"
                            value={stage.minimum_separation ??
                                DEFAULT_MINIMUM_SEPARATION}
                            data-test="generation-separation-input-{stage.stage}"
                        />
                        <p class="text-xs text-muted-foreground">
                            How far apart two homes must stand. Raise it for
                            more room between players; lower it if there is
                            nowhere left to put everybody.
                        </p>
                        <InputError message={errors.minimum_separation} />
                    </div>

                    <!--
                        Which of two distances that number counts, and they answer different
                        questions rather than being two scales of one — so this is a choice, not a
                        conversion. Unticked is a straight line through space, the measure the
                        cluster generator also uses. Ticked is steps on the map, which ignores
                        height, so two systems sharing a hex are zero apart however far one is
                        above the other.
                    -->
                    <div class="grid gap-2">
                        <Label
                            for="separation-in-hexes-{stage.stage}"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox
                                id="separation-in-hexes-{stage.stage}"
                                name="separation_in_hexes"
                                bind:checked={separationInHexes}
                                data-test="generation-separation-hexes-input-{stage.stage}"
                            />
                            <span>Count the separation in hexes</span>
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            {#if separationInHexes}
                                Reach on the map: how many hexes lie between two
                                players. Height plays no part, so two systems in
                                the same hex are zero apart however far one is
                                above the other.
                            {:else}
                                Distance through space, the straight line
                                between two systems — the same measure the
                                cluster itself was scattered by.
                            {/if}
                        </p>
                        <InputError message={errors.separation_in_hexes} />
                    </div>
                {/if}
            {/snippet}
        </Form>
    {/if}

    {#if stage.stage === 'home_stellia' && canRun}
        <!--
            Not built yet, and shown anyway so the workflow reads honestly: placing homes from a
            prepared file is how this stage is meant to work eventually, and generating them is the
            interim. Inert in every sense — there is no route behind it, no controller method and no
            column, and the gamemaster route sweep in `GameManagementTest` is what keeps it that way.
        -->
        <div
            class="grid gap-2 rounded-lg border border-dashed border-border bg-muted/30 p-4"
            aria-disabled="true"
            data-test="home-stellia-template-upload"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Label
                    for="home-stellia-template"
                    class="text-muted-foreground"
                >
                    Upload a home stellia template
                </Label>
                <Badge variant="outline">Future implementation</Badge>
            </div>
            <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <Input
                    id="home-stellia-template"
                    type="file"
                    disabled
                    tabindex={-1}
                    data-test="home-stellia-template-input"
                />
                <Button
                    type="button"
                    variant="secondary"
                    disabled
                    tabindex={-1}
                    data-test="home-stellia-template-button"
                >
                    <Upload class="h-4 w-4" />
                    Upload
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                A template will one day place every home from a prepared file,
                for a game whose starting positions are decided in advance.
                Generate them above for now.
            </p>
        </div>
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
