<script module lang="ts">
    import { dashboard } from '@/routes';
    import { show as gameShow } from '@/routes/games';
    import type { BreadcrumbItem, PlayerGame } from '@/types';

    /**
     * The last crumb is the game's own name, which only the server knows, so the layout export is a
     * *function*: the Svelte adapter calls it with the page props and spreads the result into the
     * layout chosen in `app.ts`. The first crumb is the dashboard rather than an index of games —
     * there is no player games list either, because the dashboard already is one.
     */
    export const layout = (props: {
        game: PlayerGame;
    }): { breadcrumbs: BreadcrumbItem[] } => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
            {
                title: props.game.name,
                href: gameShow(props.game.id),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Save from 'lucide-svelte/icons/save';
    import GameController from '@/actions/App/Http/Controllers/Player/GameController';
    import AppHead from '@/components/AppHead.svelte';
    import ClusterHexMap from '@/components/ClusterHexMap.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import LocationSystemPanel from '@/components/LocationSystemPanel.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { gameStatusVariants } from '@/lib/game-status';
    /*
     * `PlayerGame` is deliberately not re-imported here: the two `<script>` blocks share one module
     * scope, so the import in `<script module>` above is already visible and a second one is a
     * duplicate-identifier error under `svelte-check`.
     */
    import type { ClusterLocation, LocationDetail, PlayerSeat } from '@/types';

    let {
        game,
        seat,
        locations,
        homeSystem,
    }: {
        game: PlayerGame;
        seat: PlayerSeat;
        /*
         * One location, or none. Never the cluster: `Player\GameController` shapes the seat's own
         * home rather than reusing the omniscient `PresentsGeneration::presentLocations()`, and the
         * rest of the map is a thing to be explored rather than a thing to be withheld on the client.
         */
        locations: ClusterLocation[];
        homeSystem: LocationDetail | null;
    } = $props();

    /*
     * The empire name box. A writable `$derived` off the server's value, so a refused save snaps back
     * rather than leaving the field showing a name the empire does not have. It starts from the
     * default when the empire has not been named, which is the whole point of the server sending both:
     * the field is never empty, and saving it unchanged is a perfectly good answer.
     */
    let empireName = $derived<string>(
        seat.empire_name ?? seat.empire_name_default,
    );

    /* A writable `$derived` for the same reason, rather than `$state` seeded once from the props. */
    let emailNotifications = $derived<boolean>(seat.email_notifications);

    const isNamed = $derived(seat.empire_name !== null);
</script>

<AppHead title={game.name} />

<h1 class="sr-only">{game.name}</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title={game.name}
        description="{game.short_name} · empire {seat.number} · {seat.empire_name ??
            seat.empire_name_default}"
    />

    <section class="space-y-4" aria-labelledby="standing-heading">
        <div id="standing-heading">
            <Heading
                variant="small"
                title="Where the game stands"
                description="A turn is a quarter, and four quarters make a year. Turn 0 is the setup turn, before the first quarter of the first year."
            />
        </div>

        <dl
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-3"
            data-test="game-standing"
        >
            <div>
                <dt class="text-sm text-muted-foreground">Status</dt>
                <dd class="mt-1">
                    <Badge variant={gameStatusVariants[game.status]}>
                        {game.status_label}
                    </Badge>
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Turn</dt>
                <dd class="mt-1 font-medium" data-test="game-turn">
                    {game.turn}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Calendar</dt>
                <dd class="mt-1 font-medium" data-test="game-calendar">
                    Year {game.year}, quarter {game.quarter}
                </dd>
            </div>
        </dl>
    </section>

    <section class="space-y-4" aria-labelledby="empire-heading">
        <div id="empire-heading">
            <Heading
                variant="small"
                title="Your empire"
                description="How this game refers to you, and how it reaches you."
            />
        </div>

        <Form
            {...GameController.updateProfile.form(game.id)}
            options={{ preserveScroll: true }}
            class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2"
        >
            {#snippet children({ errors, processing })}
                <!--
                    The empire number is text and never an input. It was assigned when the seat was
                    created, it is what the engine's history calls this empire, and
                    `GameProfileUpdateRequest` does not validate the field — so a posted `number` is
                    dropped rather than written, whatever this form renders.
                -->
                <div class="sm:col-span-2">
                    <p class="text-sm text-muted-foreground">Empire number</p>
                    <p class="text-2xl font-semibold" data-test="empire-number">
                        {seat.number}
                    </p>
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="empire-name">Empire name</Label>
                    <Input
                        id="empire-name"
                        type="text"
                        name="empire_name"
                        bind:value={empireName}
                        required
                        maxlength={60}
                        autocomplete="off"
                        data-test="empire-name-input"
                    />
                    <InputError message={errors.empire_name} />
                    <p class="text-xs text-muted-foreground">
                        {#if isNamed}
                            The name the other empires will know you by.
                        {:else}
                            You have not named your empire yet — this is what it
                            is called meanwhile. Something better would be an
                            improvement.
                        {/if}
                    </p>
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label
                        for="email-notifications"
                        class="flex items-center space-x-3"
                    >
                        <Checkbox
                            id="email-notifications"
                            bind:checked={emailNotifications}
                            data-test="email-notifications-input"
                        />
                        <span>Email me about this game</span>
                    </Label>
                    <!--
                        The checkbox carries no `name`, and this hidden field is what the form posts.

                        `Checkbox` renders its own hidden input only while it is *ticked*, which is
                        right for an optional flag and wrong here: an unticked box would post nothing,
                        and a field that is absent cannot turn anything off. Posting `0` explicitly is
                        what makes unticking mean something, and it is why the server rule is
                        `required|boolean` rather than the `sometimes|boolean` used elsewhere.
                    -->
                    <input
                        type="hidden"
                        name="email_notifications"
                        value={emailNotifications ? '1' : '0'}
                    />
                    <InputError message={errors.email_notifications} />
                    <p class="text-xs text-muted-foreground">
                        Off by default. Today the only thing that sends is the
                        game becoming active.
                    </p>
                </div>

                <div class="flex items-end sm:col-span-2">
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="save-profile-button"
                    >
                        {#if processing}
                            <Spinner />
                        {:else}
                            <Save class="h-4 w-4" />
                        {/if}
                        Save
                    </Button>
                </div>
            {/snippet}
        </Form>
    </section>

    <section class="space-y-4" aria-labelledby="cluster-heading">
        <div id="cluster-heading">
            <Heading
                variant="small"
                title="The cluster"
                description="Your own system, and the centre of the cluster it sits in. Everywhere else is dark until you have been there."
            />
        </div>

        {#if locations.length > 0}
            <!--
                No `selected` and no `onSelect`: with one system there is nothing to choose between,
                and the probe report below is already showing it. The map still draws the whole grid
                and lights the centre hex, so the one mark on it reads as a position rather than as
                the extent of the world.
            -->
            <ClusterHexMap {locations} />

            <LocationSystemPanel detail={homeSystem} />
        {:else}
            <div
                class="rounded-lg border border-border p-6 text-center"
                data-test="cluster-unavailable"
            >
                <p class="font-medium">
                    {#if game.is_active}
                        You have not been placed in the cluster
                    {:else}
                        The cluster opens when the game does
                    {/if}
                </p>
                <p
                    class="mx-auto mt-1 max-w-prose text-sm text-muted-foreground"
                >
                    {#if game.is_active}
                        Your seat was added after the homes were arranged, so
                        there is nowhere to show you yet. The gamemaster can
                        place you.
                    {:else}
                        A game in setup is still being built, and its world can
                        still be thrown away and drawn again. Name your empire
                        in the meantime — that part keeps.
                    {/if}
                </p>
            </div>
        {/if}
    </section>
</div>
