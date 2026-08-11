<?php

use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Appearance / theme first paint
|--------------------------------------------------------------------------
|
| The theme has to be right in the very first bytes the browser paints, which makes it Blade output
| rather than Inertia props — so these assertions read the raw response HTML.
|
| Two mechanisms in resources/views/app.blade.php produce it, and both are covered here: the @class
| directive resolves an explicit light/dark choice server-side, and a blocking inline script resolves
| "system" against prefers-color-scheme, which the server cannot know. The client half
| (resources/js/lib/theme.svelte.ts) reads back the same cookie so hydration re-applies what is
| already on screen; only a browser can prove the absence of a visible flip, but everything the
| server contributes is asserted below.
|
| These tests also stand as the guard on the cookie staying out of encryptCookies(): if `appearance`
| were dropped from the `except` list in bootstrap/app.php, the middleware would fail to decrypt a
| plainly-written cookie and discard it, and the dark/light cases here would fail.
|
*/

/**
 * The opening <html> tag, which is where the server-resolved theme class lands.
 */
function appearanceHtmlTag(TestResponse $response): string
{
    preg_match('/<html[^>]*>/', (string) $response->getContent(), $matches);

    return $matches[0] ?? '';
}

test('a persisted appearance choice is what the next full page load paints', function (string $choice, bool $expectsDarkClass) {
    /*
     * There is no server-side writer for this cookie: resources/js/lib/theme.svelte.ts writes it
     * from updateAppearance() when the user picks a theme, as a plain unencrypted cookie. Sending
     * exactly what that function writes covers the server-read half of the round trip — the picker's
     * value is what the next full page load paints from.
     */
    $response = $this->withUnencryptedCookie('appearance', $choice)->get(route('home'));

    $response->assertOk();

    expect(str_contains(appearanceHtmlTag($response), 'dark'))->toBe($expectsDarkClass);
})->with([
    'an explicit dark choice paints dark server-side' => ['dark', true],
    'an explicit light choice does not' => ['light', false],
    'system cannot be resolved server-side, so it does not' => ['system', false],
]);

test('a request with no appearance cookie behaves as system and ships the resolving script', function () {
    $response = $this->get(route('home'));

    $response->assertOk();

    // Nothing to resolve server-side: the OS preference is only knowable in the browser.
    expect(appearanceHtmlTag($response))->not->toContain('dark');

    // So the client must be handed the means to resolve it before it paints.
    $response->assertSee('window.matchMedia(\'(prefers-color-scheme: dark)\')', escape: false);
    $response->assertSee('document.documentElement.classList.toggle(\'dark\', isDark)', escape: false);
    $response->assertSee('document.documentElement.style.colorScheme', escape: false);
});

test('the resolving script is emitted before every asset the vite directive renders', function () {
    /*
     * Ordering is the whole point of the script. Resolving the theme after the stylesheet has been
     * requested is what produces the flash it exists to prevent, so assert the position rather than
     * trusting the template's line order to survive an edit.
     */
    $content = (string) $this->get(route('home'))->getContent();

    $script = strpos($content, 'prefers-color-scheme: dark');
    $modulePreload = strpos($content, 'modulepreload');
    $stylesheet = strpos($content, 'rel="stylesheet"');
    $moduleScript = strpos($content, 'type="module"');

    expect($script)->toBeInt()
        ->and($modulePreload)->toBeInt()
        ->and($stylesheet)->toBeInt()
        ->and($moduleScript)->toBeInt()
        ->and($script)->toBeLessThan($modulePreload)
        ->and($script)->toBeLessThan($stylesheet)
        ->and($script)->toBeLessThan($moduleScript);
});

test('the appearance cookie cannot break out of the resolving script', function () {
    /*
     * The cookie is attacker-settable, and it is interpolated into a <script> body, so the escaping
     * is load-bearing: swapping @js() for {{ }} or {!! !!} would turn this into an XSS hole. Pinning
     * the value to Js::from() tracks whatever escaping the framework does rather than a hardcoded
     * encoding, while still failing for either substitution.
     */
    $hostile = "dark';alert(1);//";

    $response = $this->withUnencryptedCookie('appearance', $hostile)->get(route('home'));

    $response->assertOk();

    $content = (string) $response->getContent();

    expect($content)
        ->not->toContain("dark';alert(1)")
        ->toContain('const appearance = '.Js::from($hostile).';');

    // A value that merely starts with "dark" is not the dark theme.
    expect(appearanceHtmlTag($response))->not->toContain('class="dark"');
});

test('the client theme store reads the cookie and keeps no second source of truth', function () {
    /*
     * The client half of the fix cannot be exercised over HTTP, but the decision behind it can be
     * held in place. The starter kit read localStorage first, which is a store the server cannot see:
     * whenever it drifted from the cookie, hydration flipped the theme away from what had already
     * been painted. Reintroducing a read or a write here would restore that flash silently, so assert
     * the cookie is the only store. Matching on the call sites, not the word, leaves the explanatory
     * comment in that file free to name what was removed.
     */
    $store = file_get_contents(resource_path('js/lib/theme.svelte.ts'));

    expect($store)
        ->toContain("getCookie('appearance')")
        ->toContain("setCookie('appearance'")
        ->not->toContain('localStorage.getItem')
        ->not->toContain('localStorage.setItem');
});

test('the resolving script and the html class agree on an explicit choice', function () {
    /*
     * The two mechanisms read the same cookie, so they must never contradict each other: the class is
     * what paints, and the script is what runs a moment later. A dark cookie has to produce both.
     */
    $response = $this->withUnencryptedCookie('appearance', 'dark')->get(route('home'));

    expect(appearanceHtmlTag($response))->toContain('class="dark"');

    $response->assertSee('const appearance = '.Js::from('dark').';', escape: false);
});
