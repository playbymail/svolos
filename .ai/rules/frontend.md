# Inertia + Svelte 5 frontend

Globs: `resources/js/**`

The frontend is **Svelte 5 with `@inertiajs/svelte`**, not React. When porting a pattern from a React
Inertia app, solve it idiomatically for Svelte rather than emulating the React shape.

## Page layouts are resolved centrally in `app.ts` — do not set them per page

`resources/js/app.ts` passes a `layout` callback to `createInertiaApp` that maps page-name prefixes to
layout components:

- `Welcome` → `null` (no layout)
- `auth/*` → `AuthLayout`
- `settings/*` → `[AppLayout, SettingsLayout]` (nested, outermost first)
- everything else → `AppLayout`

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
