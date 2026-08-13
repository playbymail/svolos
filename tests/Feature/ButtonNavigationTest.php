<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| A Button that navigates must be asChild + Link
|--------------------------------------------------------------------------
|
| `components/ui/button/button.svelte` always renders a `<button>` element unless `asChild`
| is set. Every unrecognised prop is spread onto it, so `<Button href="...">` produces
| `<button href="...">` — valid HTML nowhere, inert everywhere. Clicking it does nothing at
| all: no navigation, no request, no console error.
|
| Nothing else in this project can catch that. The component's props end in
| `[key: string]: unknown`, so `svelte-check` accepts `href` without complaint, and there is
| no jsdom here (see .ai/rules/general.md) so no test renders the markup. It shipped once
| already, on the agents screen, and was found by a person clicking the button in production.
|
| So it is asserted the only way it can be: by reading the source. The fix is the shape the
| rest of the application already uses — `<Button asChild>` wrapping an Inertia `<Link>` that
| takes the button's classes through the snippet's `props.class`.
|
*/

test('no Button is given an href', function () {
    $offenders = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'svelte')
        /*
         * `components/ui` is vendored generated code (see .ai/rules/frontend.md). The button
         * component itself is in there, and so is anything else free to spread props as it likes.
         */
        ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPathname(), '/components/ui/'))
        ->filter(function (SplFileInfo $file): bool {
            /*
             * `[^>]*` crosses newlines, so a multi-line `<Button …>` opening tag is matched as one
             * unit and an `href` on any of its lines is found.
             */
            return preg_match('/<Button\b[^>]*\shref=/', (string) file_get_contents($file->getPathname())) === 1;
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([], implode("\n", [
        'These components pass `href` to <Button>, which renders a dead <button href="…">.',
        'Use <Button asChild> with an Inertia <Link> inside, taking props.class:',
        '',
        '  <Button asChild>',
        '      {#snippet children(props)}',
        '          <Link href={toUrl(route())} class={props.class}>Label</Link>',
        '      {/snippet}',
        '  </Button>',
        '',
        ...$offenders,
    ]));
});
