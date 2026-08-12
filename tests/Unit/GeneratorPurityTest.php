<?php

use App\Generation\ClusterGenerator;
use App\Generation\SeededRandomizer;
use App\Generation\StelliumGenerator;
use App\Models\Game;

/*
|--------------------------------------------------------------------------
| A generator draws from its seed and from nothing else
|--------------------------------------------------------------------------
|
| This is a source-reading test, and it earns its place the way the role-separation source assertions
| do: **behaviour cannot see this bug.** A generator that called `shuffle()` or `mt_rand()` still
| returns a hundred well-separated locations and passes every constraint test in the suite — it simply
| returns a *different* hundred next time, and the seed stored against the game stops meaning
| anything. The failure would surface much later, as a game that cannot be reproduced, with nothing
| left to point at the cause.
|
| `random_int()` is on the forbidden list here and is the right call in `Game::randomSeed()`. The
| difference is the whole idea: choosing a seed wants unpredictability, using one wants repeatability.
|
*/

/**
 * Every class that is allowed to draw randomness while generating.
 *
 * @return array<int, class-string>
 */
function seededGenerators(): array
{
    return [ClusterGenerator::class, StelliumGenerator::class];
}

test('no generator reaches for an unseeded source of randomness', function (string $class) {
    $source = executableSourceOf($class);

    foreach (['mt_rand', 'rand(', 'random_int', 'shuffle(', 'array_rand', 'str_shuffle', 'uniqid'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with(seededGenerators());

test('every generator builds its randomness through the one seeded helper', function (string $class) {
    /* The positive control: the stripping left real code behind, and it is the right code. */
    expect(executableSourceOf($class))->toContain('SeededRandomizer::for');
})->with(seededGenerators());

test('the seeded randomizer is Mersenne Twister, because that is the seed we store', function () {
    /*
     * `Game::SEED_MIN`/`SEED_MAX` are the 32-bit range Mt19937 accepts. If the engine were swapped for
     * one with a different seed width, every seed already stored against a game would start meaning
     * something else — so the two are pinned together here rather than only in prose.
     */
    expect(executableSourceOf(SeededRandomizer::class))->toContain('Mt19937');

    expect(Game::SEED_MIN)->toBe(0);
    expect(Game::SEED_MAX)->toBe(4294967295);
});

test('the randomizer draws the same sequence twice from the same seed', function () {
    $first = SeededRandomizer::for(4242);
    $second = SeededRandomizer::for(4242);

    $draw = fn ($randomizer): array => array_map(
        fn (): int => $randomizer->getInt(0, 1_000_000),
        range(1, 20),
    );

    expect($draw($first))->toBe($draw($second));
});

test('the randomizer draws a different sequence from a neighbouring seed', function () {
    $draw = fn (int $seed): array => array_map(
        fn (): int => SeededRandomizer::for($seed)->getInt(0, 1_000_000),
        range(1, 1),
    );

    expect($draw(4242))->not->toBe($draw(4243));
});
