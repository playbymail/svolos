<?php

use App\Generation\ClusterGenerator;
use App\Generation\Coordinates;
use App\Generation\GenerationFailed;
use App\Generation\HomeStelliumGenerator;

/*
|--------------------------------------------------------------------------
| The home stellium generator
|--------------------------------------------------------------------------
|
| Where each player's faction begins. Two things about this generator are unlike the three before it,
| and both are what this file pins.
|
| **Failure is an ordinary outcome.** The cluster, the stelliums and the planets always succeed at the
| numbers this application ships; a gamemaster can ask this one for eight homes twelve hexes apart and
| there is genuinely no arrangement. So the throw is tested as behaviour rather than as a guard against
| a future change of constants.
|
| **The separation is counted one of two ways**, and the gamemaster picks which: a straight line
| through space by default, or steps on the hex map. They are different questions rather than two
| scales of one — two systems thirty units apart in height share a hex — so a pair that satisfies one
| can plainly fail the other, and both directions are asserted below.
|
*/

test('every home stands at least the minimum separation from every other', function (int $seed, int $separation, bool $inHexes) {
    $candidates = candidateRing();

    $placed = (new HomeStelliumGenerator)->generate($seed, $candidates, 6, $separation, $inHexes);

    expect($placed)->toHaveCount(6);
    expect(array_unique($placed))->toHaveCount(6);

    foreach ($placed as $home) {
        foreach ($placed as $other) {
            if ($home === $other) {
                continue;
            }

            /* Whichever measure was asked for, and never the other one. */
            $apart = $inHexes
                ? $candidates[$home]->hexDistanceTo($candidates[$other])
                : sqrt($candidates[$home]->squaredDistanceTo($candidates[$other]));

            expect($apart)->toBeGreaterThanOrEqual($separation);
        }
    }
})->with([
    'euclidean, the default' => [0, 5, false],
    'euclidean, another seed' => [1, 5, false],
    'euclidean, tighter' => [4242, 8, false],
    'hexes' => [0, 5, true],
    'hexes, another seed' => [4242, 5, true],
    'hexes, loose' => [7, 3, true],
    'hexes, tight' => [999_999, 8, true],
]);

test('the boundary is exact, because the Euclidean comparison is squared on both sides', function () {
    /*
     * Two systems exactly 5 apart — a 3-4-5 triangle — satisfy "at least 5" and must not be refused by
     * a `sqrt()` that rounded a hair low. This is why the check compares squared integers, the same
     * reasoning `ClusterGenerator` records for its own separation.
     */
    $pair = [new Coordinates(0, 0, 0), new Coordinates(3, 4, 0)];
    $generator = new HomeStelliumGenerator;

    expect($generator->generate(1, $pair, 2, 5))->toHaveCount(2);
    expect(fn () => $generator->generate(1, $pair, 2, 6))->toThrow(GenerationFailed::class);
});

test('the two measures disagree, which is the whole reason there is a choice', function () {
    /*
     * The case that shows they are different questions. These two systems share a column, so they are
     * the **same hex** — zero apart on the map — while standing 28 units apart through space. Neither
     * answer is wrong; they are answers to different questions, and the checkbox is which one the game
     * is played by.
     */
    $stacked = [new Coordinates(3, -4, -14), new Coordinates(3, -4, 14)];
    $generator = new HomeStelliumGenerator;

    expect($generator->generate(1, $stacked, 2, 20))->toHaveCount(2);

    expect(fn () => $generator->generate(1, $stacked, 2, 1, inHexes: true))
        ->toThrow(GenerationFailed::class);
});

test('the failure names the separation in the unit that was asked for', function () {
    $stacked = [new Coordinates(3, -4, -14), new Coordinates(3, -4, 14)];
    $generator = new HomeStelliumGenerator;

    expect(fn () => $generator->generate(1, $stacked, 2, 40))
        ->toThrow(GenerationFailed::class, 'at least 40 apart');

    expect(fn () => $generator->generate(1, $stacked, 2, 40, inHexes: true))
        ->toThrow(GenerationFailed::class, 'at least 40 hexes apart');
});

test('the same seed places the same homes, in the same order', function () {
    /*
     * The whole stage rests on this. Everything else about a run is reproducible from its seed, and an
     * arrangement that drifted would make the stored run a record of nothing.
     */
    $candidates = candidateRing();
    $generator = new HomeStelliumGenerator;

    expect($generator->generate(4242, $candidates, 5, 5))
        ->toBe($generator->generate(4242, $candidates, 5, 5));
});

test('a different attempt on the same seed gives a different arrangement', function () {
    /*
     * **This is the property the stage's interaction is built on.** `GenerateHomeStellia` seeds the
     * stream with `seed + attempt`, so a gamemaster presses Generate again *without touching the seed*
     * and gets somewhere else — which is also why `GenerationRunRequest` exempts this stage from the
     * "choose a seed other than the one that produced this" rule that every other stage lives under.
     *
     * Asserted across several consecutive attempts rather than one pair, because two arrangements
     * differing once is luck and a stream that had not moved at all would produce a run of identical
     * ones.
     */
    $candidates = candidateRing();
    $generator = new HomeStelliumGenerator;

    $arrangements = array_map(
        fn (int $attempt): string => implode(',', $generator->generate(4242 + $attempt, $candidates, 5, 5)),
        range(1, 6),
    );

    expect(array_unique($arrangements))->toHaveCount(6);
});

test('a tight but possible arrangement is found, because a doomed attempt starts over', function () {
    /*
     * Greedy placement paints itself into corners: a home excludes every candidate within the
     * separation of it, so an unlucky early draw can leave the last player with nowhere legal. The
     * restart loop is what recovers, and this is the case that exercises it — a ring of 24 cells with
     * six homes four hexes apart uses the space almost exactly.
     */
    $candidates = candidateRing();

    $placed = (new HomeStelliumGenerator)->generate(4242, $candidates, 6, 4, inHexes: true);

    expect($placed)->toHaveCount(6);
});

test('an arrangement that cannot exist fails loudly rather than spinning', function () {
    /*
     * Reachable from the screen, unlike the other generators' failures — which is why the message
     * names the separation rather than the seed, and why the controller turns it into a message on
     * that field instead of a 500.
     */
    expect(fn () => (new HomeStelliumGenerator)->generate(4242, candidateRing(), 6, 20, inHexes: true))
        ->toThrow(GenerationFailed::class, 'Try a smaller minimum separation.');
});

test('a candidate list shorter than the roster fails rather than placing what it can', function () {
    /*
     * A partial arrangement would be worse than a refusal: it leaves a player with no home and nothing
     * on the screen saying so, and the stage would still be acceptable.
     */
    $candidates = array_slice(candidateRing(), 0, 3);

    expect(fn () => (new HomeStelliumGenerator)->generate(1, $candidates, 6, 1))
        ->toThrow(GenerationFailed::class);
});

test('a game with no players produces an empty arrangement without drawing', function () {
    /*
     * An ordinary state, not an edge case — a gamemaster may build the world before seating anybody.
     * The stage still runs and is still acceptable; it simply places nothing.
     */
    expect((new HomeStelliumGenerator)->generate(4242, candidateRing(), 0, 5))->toBe([]);
});

/**
 * A ring of 24 well-spread cells to place homes among, standing in for a cluster's single-star systems.
 *
 * Hand-built rather than generated: the tests above need to know how much room there is, so that "six
 * homes four apart" is tight and "six homes twenty apart" is impossible by construction rather than by
 * whatever a seed happened to scatter.
 *
 * @return array<int, Coordinates>
 */
function candidateRing(): array
{
    $candidates = [];
    $radius = ClusterGenerator::RADIUS - 2;

    for ($step = 0; $step < 24; $step++) {
        $angle = 2 * M_PI * $step / 24;

        $candidates[] = new Coordinates(
            (int) round($radius * cos($angle)),
            (int) round($radius * sin($angle)),
            $step % 7 - 3,
        );
    }

    return $candidates;
}
