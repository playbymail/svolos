import { mount } from 'svelte';
import ImpersonationBanner from '@/components/ImpersonationBanner.svelte';

const BANNER_SLOT = 'impersonation-banner-root';

/**
 * Mount the impersonation banner once, into its own container outside Inertia's root element.
 *
 * For the same reason the toaster is mounted this way (see `flash-toast.ts`), and with more at
 * stake: layouts are resolved per page-name prefix in `resources/js/app.ts`, and an administrator
 * who is impersonating somebody can reach every one of them — the app pages, a settings page, the
 * email verification notice under `AuthLayout`, the public landing page under `PublicLayout`. A
 * banner living in a layout would vanish on whichever prefixes nobody remembered, and a banner that
 * is only *sometimes* on screen is worse than none: it teaches the administrator that its absence
 * means they are themselves.
 *
 * Mounting at the root instead makes it independent of which layout the current page resolves to.
 * The banner renders nothing at all when `auth.impersonator` is null, which is every ordinary
 * session.
 */
export function initializeImpersonationBanner(): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (document.querySelector(`[data-slot="${BANNER_SLOT}"]`)) {
        return;
    }

    const container = document.createElement('div');
    container.setAttribute('data-slot', BANNER_SLOT);
    document.body.appendChild(container);

    mount(ImpersonationBanner, { target: container });
}
