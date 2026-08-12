<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Dices from 'lucide-svelte/icons/dices';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import type { GameSeedTarget } from '@/types';
    import type { RouteFormDefinition } from '@/wayfinder';

    /*
     * The endpoint is a prop rather than an import, exactly as `GameSeatRoleForm.svelte` takes its
     * own: an administrator and a gamemaster may both set a seed, on the same terms, so the one
     * component serves both screens without importing either area's controller.
     *
     * Whether the seed may still be changed is the server's answer (`can_change_seed`, true only
     * while the game is in setup), not a status comparison repeated here. Both branches live in this
     * component so that the input and the sentence explaining why there is no input cannot drift.
     */
    let {
        action,
        game,
    }: {
        action: RouteFormDefinition<'post'>;
        game: GameSeedTarget;
    } = $props();

    /*
     * Mirrors `App\Models\Game::SEED_MAX` — the width of PHP's Mersenne Twister seed. These are input
     * hints that make a typo visible before it is posted; `GameValidationRules::gameSeedRules()` is
     * what actually refuses an out-of-range value, so a browser that ignores them changes nothing.
     */
    const seedMin = 0;
    const seedMax = 4294967295;
</script>

{#if game.can_change_seed}
    <Form
        {...action}
        options={{ preserveScroll: true }}
        class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_auto]"
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="seed">Seed</Label>
                <Input
                    id="seed"
                    type="number"
                    name="seed"
                    required
                    min={seedMin}
                    max={seedMax}
                    step={1}
                    inputmode="numeric"
                    autocomplete="off"
                    value={game.seed}
                    data-test="seed-input"
                />
                <p class="text-xs text-muted-foreground">
                    A whole number from {seedMin.toLocaleString()} to {seedMax.toLocaleString()}.
                    Two games with the same seed make the same random decisions,
                    which is what makes a run reproducible.
                </p>
                <InputError message={errors.seed} />
            </div>

            <div class="flex items-end">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="save-seed-button"
                >
                    {#if processing}
                        <Spinner />
                    {:else}
                        <Dices class="h-4 w-4" />
                    {/if}
                    Save seed
                </Button>
            </div>
        {/snippet}
    </Form>
{:else}
    <div class="rounded-lg border border-border p-4 text-sm">
        <code
            class="rounded bg-muted px-1.5 py-0.5 font-medium"
            data-test="game-seed">{game.seed}</code
        >
        <!--
            The reason comes from the server, because there are two of them and they are different
            sentences: a game that has left setup, and a game still in setup whose world has already
            been generated from this number. Inferring it from the status here told somebody "the game
            has left setup" about a game that plainly had not.
        -->
        <p class="mt-2 text-muted-foreground" data-test="game-seed-lock-reason">
            {game.seed_lock_reason}
        </p>
    </div>
{/if}
