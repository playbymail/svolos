# Inertia + Svelte 5 frontend

Globs: `resources/js/**`, `resources/views/app.blade.php`

The frontend is **Svelte 5 with `@inertiajs/svelte`**, not React. When porting a pattern from a React
Inertia app, solve it idiomatically for Svelte rather than emulating the React shape.

## Page layouts are resolved centrally in `app.ts` — do not set them per page

`resources/js/app.ts` passes a `layout` callback to `createInertiaApp` that maps page-name prefixes to
layout components:

- `Welcome`, `Docs` → `PublicLayout` (the signed-out marketing chrome: header, `Log in`, footer)
- `auth/*`, `invitations/*` → `AuthLayout`
- `settings/*` → `[AppLayout, SettingsLayout]` (nested, outermost first)
- everything else → `AppLayout`

`invitations/*` shares `AuthLayout` because those screens are signed-out and single-purpose — one form
to fill in, or one explanation of why a link is dead — which is exactly what `AuthLayout` is. They are
kept out of `pages/auth/` because that directory is the Fortify surface and registration is absent from
it (see [auth.md](auth.md)); sharing a layout is not joining that surface. See
[invitations.md](invitations.md).

A new page therefore gets its layout from where it lives. Put it under the right prefix instead of
importing a layout inside the page, and if a page needs a genuinely new layout, add a case to that
switch.

Per-page layout data (breadcrumbs and the like) is exported from the page's `<script module>` block:

```svelte
<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    };
</script>
```

This is the Svelte analogue of React's static `Dashboard.layout` property — the module-level export is
read at page resolution time and spread into the layout component as props. See
`resources/js/pages/Dashboard.svelte`. Apply it consistently on every page that needs breadcrumbs.

When the value has to come from the server, export a **function** instead of an object: the adapter
calls it with the page props and spreads the result, keeping the layout from `app.ts`.
`pages/invitations/Invalid.svelte` uses this so the server decides the heading and description the
layout renders:

```svelte
<script module lang="ts">
    export const layout = (props: { title: string; description: string }) => ({
        title: props.title,
        description: props.description,
    });
</script>
```

Do not reach for `setLayoutProps()` for this. It exists for values that change *after* render; a page
whose layout data is fixed for the life of the page should stay declarative, in one mechanism.

## The public pages must not link to a sign-up

`PublicLayout.svelte`, `pages/Welcome.svelte` and `pages/Docs.svelte` are the signed-out surface.
Registration does not exist (see [auth.md](auth.md)), so there is no `register` export in the
generated `@/routes` and importing one breaks `npm run build`. The landing page says so in words —
"accounts come from invitations" — rather than offering a link. Do not add one.

## `Toaster` is mounted once at the app root, not per layout

`initializeFlashToast()` in `resources/js/lib/flash-toast.ts` mounts the `Toaster` into its own
container appended to `document.body`, outside Inertia's `#app` element, and then subscribes to the
`flash` router event. Do **not** put `<Toaster />` back into a layout component.

The starter kit rendered it inside `AppSidebarLayout` and `AppHeaderLayout` only, which meant a toast
flashed on a signed-out screen — a password reset, say — rendered nowhere at all, because `auth/*`
resolves to `AuthLayout` and the public pages resolve to `PublicLayout`. Toasts have to be global, and
layouts are chosen per page-name prefix, so any per-layout copy is one new prefix away from being
wrong again. Mounting at the root makes it independent of which layout a page resolves to. The
container is idempotent (it bails if `[data-slot="toaster-root"]` already exists) so HMR does not
stack copies.

## The `appearance` cookie is the only theme store — never read localStorage

`resources/views/app.blade.php` resolves the theme before first paint from the `appearance` cookie: an
`@class` directive for an explicit `dark`, plus a blocking inline script that resolves `system` against
`prefers-color-scheme` (the server cannot know the OS preference, and without that script a `system`
user on a dark OS gets a light first paint that flips on hydration). The script interpolates the
cookie through `@js(...)`, which is what keeps a hostile cookie value out of the script body — keep it.

`resources/js/lib/theme.svelte.ts` then reads that same cookie back, so hydration re-applies the theme
already on screen. The starter kit also kept the choice in `localStorage` and read *that* first, which
is a second source of truth the server cannot see: the cookie has a 365-day expiry and its own
"clear cookies" button, so whenever the two drifted, the store the server had already painted from
lost and the theme visibly flipped after hydration. There is deliberately no `localStorage` any more.
`initializeTheme()` writes the cookie back on every visit to refresh its expiry.

## `NavFooter` links are internal by default; `NavItem.external` opts into a new tab

`NavFooter` used to hardcode `target="_blank"` on every item, which suited the starter kit's outbound
links but would have opened this application's own `/docs` in a new tab and bypassed Inertia. It now
renders an Inertia `Link` unless the item sets `external: true`, in which case it renders a plain
anchor with `target="_blank" rel="noopener noreferrer"`. The starter kit's "Repository" item is gone
rather than repointed — there is no repository URL for this application to link to, and a link to
`laravel/svelte-starter-kit` in a branded app is wrong either way.

## Sidebar open state: server prop for first paint, cookie for persistence

`HandleInertiaRequests` shares `sidebarOpen` from the `sidebar_state` cookie; `AppShell.svelte` feeds
it to `SidebarProvider` as `defaultOpen`; `SidebarProvider.setOpen()` writes the cookie back when the
user collapses or expands (including via `cmd/ctrl+b`). Both `appearance` and `sidebar_state` are in
the `encryptCookies(except: ...)` list in `bootstrap/app.php` — they have to be readable by JS and
by Blade, so do not remove them from it.

## Wayfinder must be regenerated through Vite, not artisan

`resources/js/actions`, `resources/js/routes`, and `resources/js/wayfinder` are generated and
gitignored. Regenerate them with `npm run build` or `npm run dev` — the Vite plugin is configured
with `formVariants: true` in `vite.config.ts`. A bare `php artisan wayfinder:generate` omits the form
variants, so imports like `login.form` vanish and `npm run types:check` fails. If you must run it
directly, pass `--with-form`.

Import route helpers from `@/routes` (named routes) and `@/actions` (controller actions). Never
hardcode a URL string.

## UI components

shadcn-svelte on Bits UI lives in `resources/js/components/ui/` and is excluded from ESLint and
Prettier (`.prettierignore`, `eslint.config.js` ignores) because it is vendored generated code — do
not reformat it, and do not add a second component library. If one component fights the port,
hand-roll that single component instead. Icons come from `lucide-svelte`; toasts from `svelte-sonner`
via `resources/js/lib/flash-toast.ts`.

### `Checkbox`'s hidden input must stay inside the `checked` guard

`Checkbox.svelte` is a `<button role="checkbox">`, so the hidden input it renders is the *only* thing
a form ever posts. That input was originally emitted whenever `name` was set, in **both** states — so
`<Checkbox name="remember" />` on the login page posted `remember=""` whether or not it was ticked,
and "Remember me" never remembered anything. Nothing caught it: `AuthenticationTest` posts `remember`
directly rather than through the component.

An unticked checkbox submits **nothing at all**, and that absence is what `boolean()` reads as false
on the server. So the guard is the whole of the semantics, and `value` defaults to `'1'` so the common
case needs no prop. This cannot be pinned by a test — there is no jsdom or testing-library here, and
Vitest covers only the pure front end (see [general.md](general.md)) — so it is written down instead.
Anything reached only through a rendered component is verified in a browser.

## Type checking

`npm run types:check` is
`svelte-check --tsconfig ./tsconfig.json --config ./vite.config.ts`. The explicit `--config` is
required: without it svelte-check walks the whole workspace looking for Svelte/Vite configs, finds
`vendor/laravel/framework/.../vite.config.js` and `vendor/laravel/wayfinder/vite.config.js`, and
prints two `No Svelte configuration found in vite config` errors on every run. Pointing it at the
project's own config makes those subordinate configs be ignored. This scopes the checker to the app's
own config; it does not suppress any diagnostic. `tsconfig.json` `include` already limits the checked
files to `resources/js/**`.

Keep TypeScript (not JSDoc) for everything outside `.svelte` files.
