<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { cn, toUrl } from '@/lib/utils';
    import { docs, home } from '@/routes';

    /**
     * The introduction is prose, not data, so it lives here rather than behind an endpoint: nothing
     * about it varies by request. `beat` is a line that stands alone in the copy and is set to carry
     * the weight of one; `stanza` is a run of those lines that belong together as a cadence.
     */
    type Passage =
        | { kind: 'prose'; text: string }
        | { kind: 'beat'; text: string }
        | { kind: 'stanza'; lines: string[] };

    const beatClass =
        'text-xl font-medium tracking-tight text-balance text-foreground sm:text-2xl';

    const movements: Passage[][] = [
        [
            {
                kind: 'prose',
                text: 'Red lights flash through the life-support bay. Smoke hangs beneath the ceiling. Somewhere beyond the bulkhead, metal groans.',
            },
            { kind: 'beat', text: 'The pods open one by one.' },
            {
                kind: 'prose',
                text: 'People wake coughing, confused, sick from suspension. Some remember climbing into the pods. Some remember the launch. No one remembers the arrival.',
            },
            {
                kind: 'stanza',
                lines: [
                    'The chronometers say you have been asleep for decades.',
                    'The voyage was supposed to take months.',
                ],
            },
        ],
        [
            { kind: 'beat', text: 'Engineering has worse news.' },
            {
                kind: 'prose',
                text: 'The main engines are gone. Burned out sometime during the voyage. Power is failing through half the ship. Repair systems answer one command in three. Machines built to maintain other machines are themselves beyond repair.',
            },
            {
                kind: 'stanza',
                lines: [
                    'You crossed the stars aboard technology your people once took for granted.',
                    'Now you must learn to live without it.',
                ],
            },
            { kind: 'beat', text: 'The knowledge remains. The tools do not.' },
        ],
        [
            { kind: 'beat', text: 'Then the planetary scans come back.' },
            { kind: 'beat', text: 'Someone has been here.' },
            {
                kind: 'prose',
                text: 'The advance expeditions reached the planet years before you. They opened mines in the hills, raised factories beside the rivers, cleared fields for farms, and filled depots with fuel, food, metals, machinery, and supplies.',
            },
            {
                kind: 'stanza',
                lines: [
                    'They prepared a world for you.',
                    'And then they vanished.',
                ],
            },
            {
                kind: 'prose',
                text: 'No voice answers from the surface. No ship rises to meet you. No lights mark an inhabited settlement.',
            },
            {
                kind: 'stanza',
                lines: [
                    'The mines remain.',
                    'The factories remain.',
                    'The stores remain.',
                    'The people do not.',
                ],
            },
            {
                kind: 'stanza',
                lines: [
                    'There will be time to ask why.',
                    'First, you have to survive.',
                ],
            },
        ],
        [
            {
                kind: 'beat',
                text: 'Your colony ship is dying in orbit, and thousands of people are waiting for you to decide what happens next.',
            },
            {
                kind: 'prose',
                text: 'You must put them on the ground. You must feed them, house them, and give them the means to build something that will last. The abandoned installations below can give you a beginning, but not everything. Some mines will matter more than others. Some factories will have to wait. Machinery, fuel, skilled labor, and transport are all limited, and every choice closes another door.',
            },
            {
                kind: 'beat',
                text: 'The ship itself may become your first great resource.',
            },
            {
                kind: 'prose',
                text: 'Its engines will never carry you home. Its hull may become buildings. Its machinery may become workshops. Its cables, reactors, computers, and stores may keep the colony alive for years—if you are willing to cut apart the vessel that brought you here.',
            },
            {
                kind: 'stanza',
                lines: ['That decision is yours.', 'So are the others.'],
            },
            {
                kind: 'prose',
                text: 'You will decide where your people settle, what they build, what they mine, what they preserve, and what they sacrifice. You will direct exploration into a world whose first settlers have disappeared. You will rebuild an industrial base from the fragments of a civilization that once crossed interstellar space.',
            },
            {
                kind: 'beat',
                text: 'And, if you survive long enough, you may build ships again.',
            },
        ],
        [
            {
                kind: 'stanza',
                lines: [
                    'Others came to this cluster before you.',
                    'Others may have survived.',
                    'Others may be making the same decisions now.',
                ],
            },
            {
                kind: 'prose',
                text: 'For the moment, you have one damaged ship, one silent world, and a colony depending on your orders.',
            },
            { kind: 'beat', text: 'What happens next is your challenge.' },
        ],
    ];
</script>

<AppHead title="The story" />

<article class="max-w-2xl">
    <span
        class="inline-flex items-center rounded-full border border-border bg-muted px-3 py-1 text-xs font-medium tracking-wide text-muted-foreground uppercase"
    >
        Player introduction
    </span>

    <h1
        class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
    >
        The klaxons wake you.
    </h1>

    <div class="mt-10 space-y-12">
        {#each movements as passages, movement (movement)}
            <section
                class={cn(
                    'space-y-6',
                    movement > 0 && 'border-t border-border/60 pt-12',
                )}
            >
                {#each passages as passage, index (index)}
                    {#if passage.kind === 'prose'}
                        <p class="text-lg leading-8 text-muted-foreground">
                            {passage.text}
                        </p>
                    {:else if passage.kind === 'beat'}
                        <p class={beatClass}>{passage.text}</p>
                    {:else}
                        <div class="space-y-3">
                            {#each passage.lines as line (line)}
                                <p class={beatClass}>{line}</p>
                            {/each}
                        </div>
                    {/if}
                {/each}
            </section>
        {/each}
    </div>

    <div class="mt-12 flex flex-wrap items-center gap-3">
        <Button variant="outline" asChild>
            {#snippet children(props)}
                <Link href={toUrl(home())} class={props.class}>
                    Back to the start
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
</article>
