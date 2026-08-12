<?php

namespace App\Generation;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The one place a generator's source of randomness is built.
 *
 * ## Every draw a generator makes comes from here
 *
 * A generator must never call `rand()`, `mt_rand()`, `shuffle()`, `array_rand()`, `str_shuffle()` or
 * `random_int()`. Each of those reads an engine this seed has no control over — the first four share a
 * global state that anything else in the process can disturb, and `random_int()` reads the system
 * CSPRNG, which cannot be seeded at all. Any one of them silently destroys reproducibility while every
 * constraint test still passes, because the output is still 100 well-separated locations; it is simply
 * a *different* 100 next time. `tests/Unit/GeneratorPurityTest.php` reads the generator sources to
 * make sure none of them appears.
 *
 * `random_int()` still has exactly one job in the application, and it is the opposite one:
 * `App\Models\Game::randomSeed()` uses it to *choose* a seed. Choosing wants unpredictability; using
 * wants repeatability.
 *
 * **Mt19937 is the engine because its seed is the seed we store.** `App\Models\Game::SEED_MIN` and
 * `SEED_MAX` are the 32-bit range Mersenne Twister accepts, so every seed a gamemaster can type maps to
 * exactly one sequence, and the same seed produces byte-identical output on any platform and any PHP
 * version that ships the engine.
 */
final class SeededRandomizer
{
    /**
     * Build the randomizer a generator draws from.
     */
    public static function for(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }
}
