<?php

use App\Models\Session;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The Session model's pure helpers
|--------------------------------------------------------------------------
|
| Digesting, comparing and user-agent parsing need no database and no HTTP request, and the
| user-agent table in particular is a long ordered list of branches that would take a dozen
| seeded rows and a dozen page renders to cover through the feature suite. What the feature
| suite does own is the part that has a surface: which of these values reach the screen, and
| the fact that the identifier never does.
|
| `hydrate()` is used rather than the factory so these stay true unit tests — no container,
| no connection — and because `Session` is deliberately not mass-assignable.
|
*/

/**
 * Build an unsaved session with the given attributes already "loaded from the database".
 *
 * @param  array<string, mixed>  $attributes
 */
function session_with(array $attributes): Session
{
    return (new Session)->newFromBuilder($attributes);
}

test('the digest is a sha256 of the session identifier', function () {
    $session = session_with(['id' => 'AbCdEf0123456789AbCdEf0123456789AbCdEf01']);

    expect($session->digest())
        ->toBe(hash('sha256', 'AbCdEf0123456789AbCdEf0123456789AbCdEf01'))
        ->toHaveLength(64)
        ->toMatch('/^[0-9a-f]{64}$/')
        ->and($session->digest())->not->toBe($session->id);
});

test('digesting is stable for one identifier and different for any other', function () {
    expect(Session::digestFor('a'))->toBe(Session::digestFor('a'))
        ->and(Session::digestFor('a'))->not->toBe(Session::digestFor('b'));
});

test('the identifier and payload are hidden from serialisation', function () {
    $session = session_with([
        'id' => 'AbCdEf0123456789AbCdEf0123456789AbCdEf01',
        'user_id' => 1,
        'payload' => base64_encode(serialize(['_token' => 'secret'])),
        'ip_address' => '127.0.0.1',
        'last_activity' => 1_700_000_000,
    ]);

    expect($session->getHidden())->toContain('id')->toContain('payload')
        ->and(array_keys($session->toArray()))->not->toContain('id')
        ->and(array_keys($session->toArray()))->not->toContain('payload')
        ->and(json_encode($session))->not->toContain('AbCdEf0123456789AbCdEf0123456789AbCdEf01');
});

test('a session knows whether it is the one making the request', function () {
    $session = session_with(['id' => 'AbCdEf0123456789AbCdEf0123456789AbCdEf01']);

    expect($session->isCurrent('AbCdEf0123456789AbCdEf0123456789AbCdEf01'))->toBeTrue()
        ->and($session->isCurrent('AbCdEf0123456789AbCdEf0123456789AbCdEf02'))->toBeFalse();
});

test('a session with no request identifier to compare against fails closed', function () {
    expect(session_with(['id' => 'x'])->isCurrent(null))->toBeFalse()
        ->and(session_with(['id' => 'x'])->isCurrent(''))->toBeFalse();
});

test('last activity is read back as an immutable date', function () {
    $session = session_with(['last_activity' => 1_700_000_000]);

    expect($session->lastActiveAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($session->lastActiveAt()->getTimestamp())->toBe(1_700_000_000)
        ->and($session->last_activity)->toBe(1_700_000_000);
});

test('the browser is parsed from the user agent', function (string $userAgent, string $expected) {
    expect(session_with(['user_agent' => $userAgent])->browser())->toBe($expected);
})->with([
    'chrome on macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Chrome'],
    'safari on macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari'],
    'firefox on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0', 'Firefox'],
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'Edge'],
    'opera on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0', 'Opera'],
    'samsung internet on android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', 'Samsung Internet'],
    'chrome on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.0.0 Mobile/15E148 Safari/604.1', 'Chrome'],
    'firefox on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/133.0 Mobile/15E148 Safari/605.1.15', 'Firefox'],
    'safari on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari'],
    'internet explorer' => ['Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko', 'Internet Explorer'],
    'curl' => ['curl/8.7.1', 'curl'],
    'something unrecognised' => ['SomeRobot/1.0', 'Unknown'],
]);

test('the platform is parsed from the user agent', function (string $userAgent, string $expected) {
    expect(session_with(['user_agent' => $userAgent])->platform())->toBe($expected);
})->with([
    'windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Windows'],
    'macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'macOS'],
    'linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Linux'],
    'android beats the linux it also claims' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36', 'Android'],
    'ios beats the mac os x it also claims' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'iOS'],
    'ipados' => ['Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'iPadOS'],
    'chromeos' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'ChromeOS'],
    'something unrecognised' => ['SomeRobot/1.0', 'Unknown'],
]);

test('a missing user agent reports an unknown browser and platform', function (?string $userAgent) {
    $session = session_with(['user_agent' => $userAgent]);

    expect($session->browser())->toBe('Unknown')
        ->and($session->platform())->toBe('Unknown');
})->with([
    'null' => [null],
    'empty' => [''],
]);
