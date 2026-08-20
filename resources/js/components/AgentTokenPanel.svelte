<!--
    Shows a freshly minted agent token, once.

    The token reaches this component through the page object's flash bag, which means the "once" is
    not something this component enforces — it is a property of the transport. Reload, navigate away,
    or mint another, and the flash is gone; the server stores only a hash and kept no copy, so there
    is nothing to come back for. That is why there is no dismiss state to persist and nothing to clear
    on unmount.

    The copy button prefers the async clipboard API and falls back to selecting the text, because
    `navigator.clipboard` is undefined outside a secure context — an installation reached over plain
    http on a LAN would otherwise get a button that silently does nothing.
-->
<script lang="ts">
    import Check from '@lucide/svelte/icons/check';
    import Copy from '@lucide/svelte/icons/copy';
    import KeyRound from '@lucide/svelte/icons/key-round';
    import { Button } from '@/components/ui/button';
    import type { AgentTokenFlash } from '@/types';

    let { flash }: { flash: AgentTokenFlash } = $props();

    let copied = $state(false);
    let tokenElement = $state<HTMLElement | null>(null);

    async function copyToken(): Promise<void> {
        if (navigator.clipboard) {
            await navigator.clipboard.writeText(flash.token);
            copied = true;

            return;
        }

        /* No clipboard API: select the text so the reader can copy it themselves. */
        if (tokenElement) {
            const range = document.createRange();
            range.selectNodeContents(tokenElement);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(range);
        }
    }
</script>

<section
    class="space-y-3 rounded-lg border border-primary/40 bg-primary/5 p-4"
    aria-labelledby="agent-token-heading"
    data-test="agent-token-panel"
>
    <div class="flex items-center gap-2">
        <KeyRound class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
        <h2 id="agent-token-heading" class="font-medium tracking-tight">
            {flash.agent}'s token for {flash.game}
        </h2>
    </div>

    <p class="text-sm text-muted-foreground">
        Copy this now. It is stored hashed, so this is the only time it can be
        shown — if you lose it, mint a new one, which stops this one working.
    </p>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <code
            bind:this={tokenElement}
            class="flex-1 overflow-x-auto rounded-md border border-border bg-background px-3 py-2 font-mono text-sm break-all"
            data-test="agent-token-value">{flash.token}</code
        >

        <Button
            type="button"
            variant="secondary"
            onclick={copyToken}
            data-test="copy-agent-token"
        >
            {#if copied}
                <Check class="h-4 w-4" aria-hidden="true" />
                Copied
            {:else}
                <Copy class="h-4 w-4" aria-hidden="true" />
                Copy
            {/if}
        </Button>
    </div>

    <p class="text-sm text-muted-foreground">
        The agent sends it as <code class="font-mono"
            >Authorization: Bearer &lt;token&gt;</code
        > to the API. It works only for this seat, in this game.
    </p>
</section>
