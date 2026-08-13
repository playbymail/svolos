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
     *
     * A key whose value is **null** is dropped rather than printed. Null means the stage had nothing
     * to record — a template that was drawn rather than read from a file, a lone home with no nearest
     * neighbour to measure against — and `String(null)` renders that as the word "null" beside a
     * label, which reads as a fault rather than as an absence.
     */
    function summaryEntries(
        summary: Record<string, unknown> | null,
    ): { key: string; label: string; value: string }[] {
        if (summary === null) {
            return [];
        }

        return Object.entries(summary).flatMap(([key, value]) => {
            if (value === null) {
                return [];
            }

            if (typeof value === 'object') {
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

    /*
     * Which of the two ways to settle a template the gamemaster is using. Writable off nothing, unlike
     * the separation above: there is no stored flag to inherit, because a run remembers the *document*
     * it read and not which control produced it. Starting unticked makes uploading the default, which
     * is the deliberate choice — a game whose homes are decided in advance is the reason the stage
     * exists, and drawing one is the fallback.
     *
     * The file input is disabled rather than hidden while the box is ticked, so the layout does not
     * move under the pointer as somebody makes up their mind. A disabled input posts nothing, which is
     * exactly what `required_without:generate_template` expects.
     */
    let generateTemplate = $state(false);

    const isTemplateStage = $derived(stage.stage === 'home_stellia_template');

    /**
     * A document of the shape the parser accepts, for the gamemaster writing their first one.
     *
     * Kept short — three planets rather than nine — because it is a shape to copy rather than a
     * template to use: what it has to show is the two keys every planet needs and the four more that
     * only the home world carries.
     */
    const exampleTemplate = JSON.stringify(
        {
            planets: [
                { ordinal: 1, type: 'rocky', habitability: 4 },
                {
                    ordinal: 2,
                    type: 'rocky',
                    habitability: 25,
                    home: true,
                    fuel: 5,
                    metals: 5,
                    minerals: 5,
                },
                { ordinal: 3, type: 'icy', habitability: 1 },
            ],
        },
        null,
        2,
    );
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
                        {#if isTemplateStage && !generateTemplate}
                            The seed is recorded either way. An uploaded
                            template is read from the document rather than drawn
                            from it, so any seed will do here.
                        {:else if stage.state !== 'review'}
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
                        {:else if isTemplateStage && !generateTemplate}
                            <Upload class="h-4 w-4" />
                        {:else}
                            <Dices class="h-4 w-4" />
                        {/if}
                        <!--
                            "Try another seed" is the wrong label for the two stages where the seed
                            need not change: for the home stellia, regenerating is asking for another
                            arrangement of the same world, and for an uploaded template the seed had
                            nothing to do with what came out.
                        -->
                        {#if isTemplateStage && !generateTemplate}
                            Upload
                        {:else if stage.state !== 'review'}
                            Generate
                        {:else if stage.stage === 'home_stellia'}
                            Try another arrangement
                        {:else}
                            Try another seed
                        {/if}
                    </Button>
                </div>

                {#if isTemplateStage}
                    <!--
                        The two ways to settle a template, as one control and its alternative. They
                        share this stage's single form and its single submit, because they are two
                        answers to one question rather than two things a gamemaster might do.

                        The file input keeps the `data-test` hooks the inert placeholder that stood
                        here carried, since they named the right things all along.
                    -->
                    <div class="grid gap-2 sm:col-span-2">
                        <Label
                            for="generate-template-{stage.stage}"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox
                                id="generate-template-{stage.stage}"
                                name="generate_template"
                                bind:checked={generateTemplate}
                                data-test="generation-generate-template-input"
                            />
                            <span
                                >Generate a template instead of uploading one</span
                            >
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            {#if generateTemplate}
                                Nine planets, with the third as the home world
                                at the top of the habitability scale. What the
                                seed decides is what they are worth.
                            {:else}
                                Upload the home system every player will begin
                                in. Tick the box to have one drawn instead.
                            {/if}
                        </p>
                    </div>

                    <div
                        class="grid gap-2 sm:col-span-2"
                        data-test="home-stellia-template-upload"
                    >
                        <Label
                            for="home-stellia-template"
                            class={generateTemplate
                                ? 'text-muted-foreground'
                                : undefined}
                        >
                            Template document
                        </Label>
                        <Input
                            id="home-stellia-template"
                            type="file"
                            name="template"
                            accept="application/json,.json"
                            disabled={generateTemplate}
                            data-test="home-stellia-template-input"
                        />
                        <InputError message={errors.template} />

                        <details class="text-xs text-muted-foreground">
                            <summary class="cursor-pointer">
                                What a template document looks like
                            </summary>
                            <p class="mt-2">
                                Every planet needs a type and a habitability.
                                Exactly one carries <code>"home": true</code> and
                                its three deposits — that is the world the players
                                begin on, and the only one identical for everybody.
                                The rest are drawn for each player.
                            </p>
                            <pre
                                class="mt-2 overflow-x-auto rounded bg-muted p-2">{exampleTemplate}</pre>
                        </details>
                    </div>
                {/if}

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

    {#if stage.history.length > 0}
        <!--
            The attempts that were tried and dropped. They produced nothing that still exists, so what
            was asked for is all that is left of them — and it is the thing worth keeping.

            Which is the seed, except for a template read from a document: there the number decided
            nothing and the file name is what somebody would recognise. Both are on the payload, and
            the one that identifies the attempt is the one shown.
        -->
        <details class="text-sm" data-test="generation-history-{stage.stage}">
            <summary class="cursor-pointer text-muted-foreground">
                {stage.history.length} earlier
                {stage.history.length === 1 ? 'attempt' : 'attempts'}
            </summary>
            <ul class="mt-2 space-y-1">
                {#each stage.history as attempt (attempt.attempt)}
                    <li class="text-muted-foreground">
                        Attempt {attempt.attempt} ·
                        {attempt.file ? 'file' : 'seed'}
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">
                            {attempt.file ?? attempt.seed}
                        </code>
                        · {attempt.generated_at_diff ?? '—'}
                    </li>
                {/each}
            </ul>
        </details>
    {/if}
</section>
