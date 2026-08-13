import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Vitest runs against its own config, deliberately not `vite.config.ts`.
 *
 * That config exists to build the application, and two of its plugins are actively hostile to a test
 * run: `laravel()` performs an environment check — which is why `vite.config.ts` already carries a
 * `LARAVEL_BYPASS_ENV_CHECK` hack for `svelte-check` — and `wayfinder()` regenerates route types on
 * `buildStart`, 1.7 seconds that would be paid on every invocation to produce something no test reads.
 *
 * What is covered here is the *pure* half of the front end: modules that are arithmetic and nothing
 * else, where a wrong answer is wrong everywhere and no amount of looking at the screen shows it. No
 * DOM, no component rendering, and so no `jsdom` and no Svelte plugin — components are covered the way
 * this project already covers them, through Feature tests over the payloads they render.
 */
export default defineConfig({
    resolve: {
        /*
         * Declared rather than inherited. Nothing in `vite.config.ts` sets this alias — it resolves
         * from `tsconfig.json`'s `paths` — and relying on a test runner to pick up a compiler setting
         * is a dependency worth not having.
         */
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        /*
         * Co-located with the module under test, so `npm run format` (which targets `resources/`) and
         * `tsconfig.json`'s `include` both already cover the tests without widening a glob.
         */
        environment: 'node',
    },
});
