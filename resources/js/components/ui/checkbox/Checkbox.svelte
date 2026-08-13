<script lang="ts">
    import { cn } from '@/lib/utils';
    import Check from 'lucide-svelte/icons/check';

    let {
        checked = $bindable(false),
        disabled = false,
        class: className = '',
        id,
        name,
        value,
        ...rest
    }: {
        checked?: boolean;
        disabled?: boolean;
        class?: string;
        id?: string;
        name?: string;
        value?: string;
        [key: string]: unknown;
    } = $props();
</script>

<button
    type="button"
    role="checkbox"
    aria-checked={checked}
    data-state={checked ? 'checked' : 'unchecked'}
    data-slot="checkbox"
    {disabled}
    {id}
    class={cn(
        'peer border-input data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-lg border shadow-xs transition-shadow outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
        className,
    )}
    onclick={() => { if (!disabled) checked = !checked; }}
    {...rest}
>
    {#if checked}
        <div data-slot="checkbox-indicator" class="grid place-content-center text-current transition-none">
            <Check class="size-3.5" />
        </div>
    {/if}
</button>
<!--
    The value a form posts, and the reason it is inside the `checked` guard.

    This is a `<button role="checkbox">` rather than a native input, so the hidden field is the only
    thing a form ever sees. Rendering it unconditionally posted the name in *both* states — which is
    how "Remember me" came to send `remember=""` whether or not it was ticked, and never remembered
    anything. An unticked checkbox submits nothing at all; that absence is what `boolean()` reads as
    false on the server, so the guard is the whole of the semantics.

    `value` defaults to `'1'` so the common case needs no prop and posts something `boolean()` accepts.
-->
{#if checked && name}
    <input type="hidden" {name} value={value ?? '1'} />
{/if}
