import { createInertiaApp } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import PublicLayout from '@/layouts/PublicLayout.svelte';
import SettingsLayout from '@/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import { initializeTheme } from '@/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Docs':
                return PublicLayout;
            /*
             * `invitations/*` shares AuthLayout with the Fortify screens. The invitation pages are
             * signed-out, single-purpose screens — one form to fill in, or one explanation of why a
             * link is dead, with nothing to navigate to in either case — which is exactly what
             * AuthLayout is: a centred card with a heading and a description. PublicLayout would
             * wrap them in marketing chrome (header, `Log in`, footer) inviting the visitor to
             * wander off mid-signup, and AppLayout assumes an authenticated user with a sidebar.
             *
             * They live under `invitations/` rather than in `auth/` on purpose: `auth/**` is the
             * Fortify surface, and registration is deliberately absent from it (see
             * `.ai/rules/auth.md`). Sharing a layout is not the same as joining that surface.
             */
            case name.startsWith('invitations/'):
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
