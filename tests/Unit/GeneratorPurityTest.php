<?php

use App\Generation\ClusterGenerator;
use App\Generation\PlanetGenerator;
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
 * Every class that owns a seed and must therefore open the stream itself.
 *
 * @return array<int, class-string>
 */
function seededGenerators(): array
{
    return [ClusterGenerator::class, StelliumGenerator::class, PlanetGenerator::class];
}

/**
 * Everything that runs while a world is being generated and must not reach for randomness of its own.
 *
 * A superset of `seededGenerators()`, and the two are separate for a reason worth knowing before
 * merging them. A helper extracted out of a generator — a dice roller, a weighted table — *receives* a
 * randomizer and so never calls `SeededRandomizer::for`, which would fail the containment test below;
 * and the tempting fix, calling `SeededRandomizer::for` inside the helper, is a genuine bug, because it
 * would restart the stream on every call and make every planet in a game identical. So a helper
 * belongs on this list and not on that one. There is no such helper today — `PlanetGenerator` keeps
 * its `roll()` and `pick()` private for exactly this reason — and the split is what makes adding one
 * safe rather than a trap.
 *
 * @return array<int, class-string>
 */
function generationSources(): array
{
    return seededGenerators();
}

test('nothing in the generation subsystem reaches for an unseeded source of randomness', function (string $class) {
    $source = executableSourceOf($class);

    /*
     * `random(` is not redundant with `rand(`: the letters `rand` in `random(` are followed by an `o`,
     * so the original list let `Arr::random()`, `Str::random()` and `$collection->random()` straight
     * through — and a weighted pick is exactly where somebody reaches for `collect($weights)->random()`.
     * It is written with the parenthesis on purpose, so it does not match `$randomizer->`.
     *
     * `new Randomizer` is here because a randomizer built with no engine defaults to `Random\Engine\Secure`,
     * which is a CSPRNG that cannot be seeded — it would pass every other check on this list while
     * quietly making the world unreproducible. Never shorten it to `Randomizer`, which matches
     * `SeededRandomizer` and fails everything.
     */
    $forbidden = [
        'mt_rand', 'rand(', 'random_int', 'random_bytes', 'random(', 'randomElement', 'randomDigit',
        'shuffle(', 'array_rand', 'str_shuffle', 'uniqid', 'lcg_value', 'new Randomizer',
    ];

    foreach ($forbidden as $call) {
        expect($source)->not->toContain($call);
    }
})->with(generationSources());

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
