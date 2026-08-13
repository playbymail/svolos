<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';
    import { create, index as agentsIndex } from '@/routes/admin/agents';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
            {
                title: 'Agents',
                href: agentsIndex(),
            },
            {
                title: 'Create',
                href: create(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import BotMessageSquare from 'lucide-svelte/icons/bot-message-square';
    import AgentController from '@/actions/App/Http/Controllers/Admin/AgentController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';

    let { emailDomain }: { emailDomain: string } = $props();
</script>

<AppHead title="Create an agent" />

<h1 class="sr-only">Create an agent</h1>

<div class="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
    <Heading
        title="Create an agent"
        description="An agent is an account nobody signs in to. Create it here, seat it at a game from that game's roster, then issue it a token."
    />

    <Form
        {...AgentController.store.form()}
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
                    placeholder="Cartographer"
                />
                <p class="text-sm text-muted-foreground">
                    How the agent appears in rosters and, before long, in turn
                    reports.
                </p>
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="email"
                    >Address <span class="text-muted-foreground"
                        >(optional)</span
                    ></Label
                >
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    placeholder="derived from the name"
                />
                <p class="text-sm text-muted-foreground">
                    Left blank, one is derived from the name on <code
                        class="font-mono">{emailDomain}</code
                    >, a reserved domain that reaches no mailbox. An agent never
                    receives email; the address is only an identifier.
                </p>
                <InputError message={errors.email} />
            </div>

            <Button
                type="submit"
                disabled={processing}
                data-test="store-agent-button"
            >
                {#if processing}
                    <Spinner />
                {:else}
                    <BotMessageSquare class="h-4 w-4" aria-hidden="true" />
                {/if}
                Create agent
            </Button>
        {/snippet}
    </Form>
</div>
