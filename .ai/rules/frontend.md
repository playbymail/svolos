# Inertia + Svelte 5 frontend

Globs: `resources/js/**`, `resources/views/app.blade.php`

The frontend is **Svelte 5 with `@inertiajs/svelte`**, not React. When porting a pattern from a React
Inertia app, solve it idiomatically for Svelte rather than emulating the React shape.

## Page layouts are resolved centrally in `app.ts` — do not set them per page

`resources/js/app.ts` passes a `layout` callback to `createInertiaApp` that maps page-name prefixes to
layout components:

- `Welcome`, `Docs`, `Story` → `PublicLayout` (the signed-out marketing chrome: header, `Log in`, footer)
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

`PublicLayout.svelte`, `pages/Welcome.svelte`, `pages/Docs.svelte` and `pages/Story.svelte` are the
signed-out surface.
Registration does not exist (see [auth.md](auth.md)), so there is no `register` export in the
generated `@/routes` and importing one breaks `npm run build`. The landing page says so in words —
"accounts come from invitations" — rather than offering a link. Do not add one.

## The player introduction lives in `pages/Story.svelte`, and stays guest-reachable

`/story` is the game's backstory and the only long-form thing a first-time visitor comes here to
read. It carries no `auth` and no `verified` — there is no public sign-up, so anything behind the
sign-in is unreachable to the audience the page is written for, and
`tests/Feature/StoryTest.php` asserts the absence of that middleware rather than only a 200.

The prose is a `Passage[][]` inside the component and is the **only** copy of the text the
application ships: `docs/player-copy.txt` is the author's draft, not a runtime source, and nothing
reads it. Edit the component when the copy changes. The three opening lines are quoted again in
`pages/Welcome.svelte`'s teaser block, which is the way into the page for someone who lands on `/`;
`PublicLayout`'s header and footer carry the other two links.

`beat` is a line the copy sets alone and the page sets to carry that weight, `stanza` is a run of
them read as one cadence, and `prose` is a muted paragraph. That contrast is the page's whole design
— flattening it into uniform paragraphs loses the rhythm the copy was written with.

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

## A keyed `{#each}` must be given a key that *cannot* repeat

Svelte throws `each_key_duplicate` when a keyed `{#each}` sees the same key twice, and the whole
subtree stops rendering. There is nothing on the screen to say so — a panel that was showing its
loading skeleton simply keeps showing it, and the only trace is
`Uncaught Error: https://svelte.dev/e/each_key_duplicate` in the console (and in
`storage/logs/browser.log`, which Boost collects).

A row id from the database cannot repeat. **A key computed from the data can**, and that is the case
to be careful with: grouping a list by neighbour and keying on the group makes an unordered payload a
blank screen. Group by looking up the value across the whole accumulator instead, so one group per
value is a property of the code rather than a hope about the server —
`resources/js/components/LocationSystemPanel.svelte` does exactly this, and
[assets.md](assets.md) has the whole story.

Note that `svelte/prefer-svelte-reactivity` fails the lint on a bare `new Map()`, even a local one
thrown away at the end of a function, and pushes you towards `SvelteMap`. For a handful of groups a
plain array and `find()` is the better answer than either.

Reach for `storage/logs/browser.log` early when a screen is stuck rather than wrong: a server payload
that is correct when you dump it, next to a screen that never renders it, is this shape of bug.

**And check the aftermath, not just the console.** A throw part way through an update leaves the
*previous* render in the DOM, so the screen afterwards shows real data in the wrong place rather than
nothing — which reads as a second, unrelated bug and gets reported as one. A refresh distinguishes
them.

## One optional prop shared by a screen belongs only under the row it answers

There is a single `locationDetail` for the whole game screen, and rows are opened one at a time. So
between opening a row and its answer landing, what is in hand is the *previous* row's system —
`ClusterLocationsTable` therefore passes it on only when `detail.id` matches the row, and shows the
loading skeleton otherwise. Without that check every way a reload can fail to land (an error, a
dropped connection, a render that throws mid-swap) puts one system's data under another's heading,
which is wrong in the one way nobody questions: it looks exactly like an answer.

Note the null: `null` means "this location has no stellium" and carries no id, so it is passed
straight through — only the open row is ever asked, so a null in hand is always this row's answer.

This is component behaviour and so is covered by neither runner — Vitest is the *pure* front end and
Pest sees the payload, not the render (see [general.md](general.md)). Extracting a predicate into
`lib/` to get it under Vitest would widen that line rather than extend it; the guard is small and
commented at the point of use instead.

## Wayfinder must be regenerated through Vite, not artisan

`resources/js/actions`, `resources/js/routes`, and `resources/js/wayfinder` are generated and
gitignored. Regenerate them with `npm run build` or `npm run dev` — the Vite plugin is configured
with `formVariants: true` in `vite.config.ts`. A bare `php artisan wayfinder:generate` omits the form
variants, so imports like `login.form` vanish and `npm run types:check` fails. If you must run it
directly, pass `--with-form`.

Import route helpers from `@/routes` (named routes) and `@/actions` (controller actions). Never
hardcode a URL string.

## `fontaine` is a build-time dependency whose absence only warns

`vite.config.ts` serves Instrument Sans through `bunny()` from `laravel-vite-plugin/fonts`, which
self-hosts the files rather than calling out to a font CDN at runtime. Its `optimizedFallbacks`
feature needs the optional peer `fontaine`, and **without it the build still succeeds** — the plugin
prints `Optimized font fallbacks require the optional "fontaine" package` and carries on. That is the
trap: nothing fails, and the only evidence is a line in a build log nobody reads and a layout that
shifts when the webfont lands.

With it installed, the emitted `fonts-*.css` gains an `Instrument Sans fallback` face carrying the
real font's metrics — `size-adjust: 103.76%`, `descent-override: 24.09%` — and `--font-instrument-sans`
lists it behind the webfont, so the text is the right size before the download finishes.

It is a **devDependency**: it reads font metrics while Vite builds and ships nothing to the browser.
That makes it load-bearing on the server all the same, because `scripts/deploy.sh` builds there. The
step is a plain `npm ci` for that reason — adding `--omit=dev` would take out Vite itself, but the
subtler loss would be this, which downgrades in silence rather than failing.

## UI components

shadcn-svelte on Bits UI lives in `resources/js/components/ui/` and is excluded from ESLint and
Prettier (`.prettierignore`, `eslint.config.js` ignores) because it is vendored generated code — do
not reformat it, and do not add a second component library. If one component fights the port,
hand-roll that single component instead. Icons come from `@lucide/svelte`; toasts from `svelte-sonner`
via `resources/js/lib/flash-toast.ts`.

Import an icon by its **own path** — `import Check from '@lucide/svelte/icons/check'` — rather than
from the package root, which is what all but one call site does. The root is a barrel re-exporting
1,776 icons and leaves it to the bundler to shake the rest out; the path form never pulls them in to
begin with.

Use the icon's **canonical** name, not a deprecated alias. `@lucide/svelte` keeps renamed icons
working — `icons/circle-help` re-exports `circle-question-mark`, types included, so `svelte-check`
passes and nothing warns. That is precisely the problem: the alias is upstream-deprecated and will go
in some future major, and until it does the identifier in our source names an icon that no longer
exists. `circle-help` was the one we had, and it is now imported under its real name.

### A `Button` that navigates is `asChild` + `Link` — `href` on a `Button` is inert

`Button.svelte` renders a `<button>` element unless `asChild` is set, and spreads every
unrecognised prop onto it. `<Button href="/somewhere">` therefore produces
`<button href="/somewhere">`, which navigates nowhere: the click does nothing at all — no request,
no console error, no clue.

Nothing catches it on the way in. The component's props end in `[key: string]: unknown`, so
`svelte-check` accepts `href` happily, and with no jsdom here nothing renders the markup to find out
(see [general.md](general.md)). It shipped once on the agents screen and was found by somebody
clicking the button in production.

Write it the way the rest of the application does — the button's classes reach the anchor through
the snippet's `props.class`:

```svelte
<Button asChild>
    {#snippet children(props)}
        <Link href={toUrl(create())} class={props.class}>Create an agent</Link>
    {/snippet}
</Button>
```

`tests/Feature/ButtonNavigationTest.php` reads every `.svelte` outside `components/ui` and fails on
an `href` inside a `<Button …>` tag, naming the file. It is a source assertion because it can only
be one.

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
