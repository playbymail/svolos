<?php

use App\Models\Invitation;

/*
|--------------------------------------------------------------------------
| Invitation token generation and hashing
|--------------------------------------------------------------------------
|
| These two are static, pure, and have no HTTP surface, which is why they are tested here
| rather than through a request: the feature suite exercises them via the endpoints, but
| nothing there would notice if `hashToken()` started returning something longer than the
| 64-character column, or if `generateToken()` produced a value the acceptance route's
| `[A-Za-z0-9]{64}` shape could not carry through a URL.
|
*/

test('a generated token is 64 URL-safe characters', function () {
    $token = Invitation::generateToken();

    expect($token)->toHaveLength(64)
        ->and($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and(rawurlencode($token))->toBe($token);
});

test('generated tokens do not repeat', function () {
    $tokens = array_map(fn (): string => Invitation::generateToken(), range(1, 50));

    expect(array_unique($tokens))->toHaveCount(50);
});

test('a hashed token is a sha256 digest that fits the column', function () {
    $token = Invitation::generateToken();
    $hash = Invitation::hashToken($token);

    expect($hash)->toBe(hash('sha256', $token))
        ->and($hash)->toHaveLength(64)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->not->toBe($token);
});

test('hashing is stable for the same token and different for any other', function () {
    $token = Invitation::generateToken();

    expect(Invitation::hashToken($token))->toBe(Invitation::hashToken($token))
        ->and(Invitation::hashToken($token))->not->toBe(Invitation::hashToken(Invitation::generateToken()));
});

test('an invitation expires a week after it is issued', function () {
    expect(Invitation::EXPIRES_AFTER_DAYS)->toBe(7);
});
