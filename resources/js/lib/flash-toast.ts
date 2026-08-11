import { router } from '@inertiajs/svelte';
import { mount } from 'svelte';
import { toast } from 'svelte-sonner';
import { Toaster } from '@/components/ui/sonner';
import type { FlashToast } from '@/types/ui';

const TOASTER_SLOT = 'toaster-root';

/**
 * Mount the toast renderer once, into its own container outside Inertia's root element.
 *
 * Toasts have to render on every screen, and layouts are resolved per page-name prefix in
 * `resources/js/app.ts` — the public landing page resolves to a layout that has no app chrome and
 * `Welcome`/`Docs` could resolve to none at all, so a per-layout `<Toaster />` copy is one edit away
 * from a screen where a flashed toast silently goes nowhere. Mounting at the app root instead makes
 * global rendering independent of which layout (if any) the current page resolves to.
 */
function mountToaster(): void {
    if (document.querySelector(`[data-slot="${TOASTER_SLOT}"]`)) {
        return;
    }

    const container = document.createElement('div');
    container.setAttribute('data-slot', TOASTER_SLOT);
    document.body.appendChild(container);

    mount(Toaster, { target: container });
}

export function initializeFlashToast(): void {
    if (typeof document === 'undefined') {
        return;
    }

    mountToaster();

    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message);
    });
}
