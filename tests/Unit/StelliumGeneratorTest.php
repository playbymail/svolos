<?php

use App\Generation\ClusterGenerator;
use App\Generation\StelliumGenerator;

/*
|--------------------------------------------------------------------------
| The stellium generator
|--------------------------------------------------------------------------
|
| The distribution is a **quota**, not a probability: over a hundred locations every game gets exactly
| 70 single-star stelliums, 20 doubles, 9 triples and one quadruple, and the seed decides only which
| location gets which. That is the decision this file pins, because the obvious "improvement" — roll
| each stellium independently — passes a test that only checks the *average* while quietly making the
| lone quadruple optional.
|
*/

test('the percentages add up to a whole cluster', function () {
    /*
     * A distribution summing to 99 would leave the last location's share to be decided by rounding,
     * which is precisely the sort of thing that is invisible until somebody counts.
     */
    expect(array_sum(StelliumGenerator::STAR_DISTRIBUTION))->toBe(100);
});

test('a hundred locations get exactly the advertised mix, whatever the seed', function (int $seed) {
    $plan = (new StelliumGenerator)->generate($seed, 100);

    expect($plan->count())->toBe(100);
    expect($plan->mix())->toBe([1 => 70, 2 => 20, 3 => 9, 4 => 1]);

    /* 70×1 + 20×2 + 9×3 + 1×4. The number is worth stating: it is what the next stage plants on. */
    expect($plan->starTotal())->toBe(141);
})->with([0, 1, 4242, 999_999]);

test('every location gets a stellium, and every stellium at least one star', function (int $seed) {
    $plan = (new StelliumGenerator)->generate($seed, ClusterGenerator::LOCATION_COUNT);

    expect($plan->starCounts)->toHaveCount(ClusterGenerator::LOCATION_COUNT);

    foreach ($plan->starCounts as $count) {
        expect($count)->toBeGreaterThanOrEqual(1);
        expect(array_key_exists($count, StelliumGenerator::STAR_DISTRIBUTION))->toBeTrue();
    }
})->with([0, 4242]);

test('the seed decides which location gets which, and the same seed decides the same way', function () {
    $first = (new StelliumGenerator)->generate(4242, 100);
    $second = (new StelliumGenerator)->generate(4242, 100);
    $different = (new StelliumGenerator)->generate(4243, 100);

    expect($first->starCounts)->toBe($second->starCounts);

    /* Same multiset, different arrangement: the mix is fixed and the *placement* is what varies. */
    expect($different->starCounts)->not->toBe($first->starCounts);
    expect($different->mix())->toBe($first->mix());
});

test('the quota still fills a cluster that is not a hundred locations', function (int $locations) {
    /*
     * Largest remainder, so the counts always sum to the cluster size — the property a plain
     * percentage-and-round would lose. `LOCATION_COUNT` is 100 today, which makes every share whole
     * and hides the rounding entirely; these are the sizes that would expose it.
     */
    $plan = (new StelliumGenerator)->generate(4242, $locations);

    expect($plan->count())->toBe($locations);
    expect(array_sum($plan->mix()))->toBe($locations);
})->with([1, 7, 13, 50, 99, 101, 250]);

test('the leftover from rounding goes to the sizes cut hardest, and does so deterministically', function () {
    /*
     * At 7 locations the exact shares are 4.9, 1.4, 0.63 and 0.07, so the whole parts give 4 + 1 + 0 + 0
     * and two are left to hand out. They go to the biggest fractional parts — the single (0.9) and then
     * the **triple** (0.63), not the double (0.4), which is the part worth pinning: the leftover follows
     * the remainder rather than the size of the share it is topping up.
     */
    $plan = (new StelliumGenerator)->generate(4242, 7);

    expect($plan->mix())->toBe([1 => 5, 2 => 1, 3 => 1, 4 => 0]);
    expect(array_sum($plan->mix()))->toBe(7);
});

test('the mix names every size the distribution mentions, including the ones that came out empty', function () {
    /*
     * A cluster too small for a quadruple must say "0 quadruples" rather than omitting the key, so a
     * reader can tell "none this time" from "not a thing".
     */
    $plan = (new StelliumGenerator)->generate(4242, 3);

    expect(array_keys($plan->mix()))->toBe(array_keys(StelliumGenerator::STAR_DISTRIBUTION));
});
