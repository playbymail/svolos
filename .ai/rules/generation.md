# Generating a game's world

Globs: `app/Generation/**`, `app/Actions/Generation/**`, `app/Enums/Generation*.php`,
`app/Models/GenerationRun.php`, `app/Models/Location.php`, `app/Models/Stellium.php`,
`app/Models/Star.php`, `app/Http/Controllers/Gamemaster/GenerationController.php`,
`app/Http/Requests/Gamemaster/GenerationRunRequest.php`, `app/Concerns/PresentsGeneration.php`,
`database/factories/{GenerationRun,Location,Stellium,Star}Factory.php`,
`resources/js/components/GenerationStageCard.svelte`,
`resources/js/components/ClusterLocationsTable.svelte`, `resources/js/types/generation.ts`,
`tests/Unit/*GeneratorTest.php`, `tests/Unit/GeneratorPurityTest.php`,
`tests/Feature/Gamemaster/GenerationTest.php`, `tests/Feature/GenerationModelTest.php`

A game's world is built in **stages**, by its gamemaster, while the game is in `Setup`: generate from
a seed, review, then accept or try another seed. Accepting unlocks the next stage. Today there are
two — `Cluster` (100 locations) and `Stelliums` (one star group per location) — and planets are next.
Read [games.md](games.md) for the seed itself and [gamemaster.md](gamemaster.md) for the area's gate.

## A generator draws from its seed and from **nothing** else

Every draw goes through `App\Generation\SeededRandomizer::for($seed)`, which is
`new Randomizer(new Mt19937($seed))`. Inside a generator, `rand()`, `mt_rand()`, `shuffle()`,
`array_rand()`, `str_shuffle()` and `random_int()` are all forbidden — the first four share a global
engine anything in the process can disturb, and `random_int()` reads a CSPRNG that cannot be seeded at
all.

**Behaviour cannot catch this.** A generator that called `shuffle()` still returns a hundred
well-separated locations and passes every constraint test in the suite; it simply returns a
*different* hundred next time, and every stored seed quietly stops meaning anything. So
`tests/Unit/GeneratorPurityTest.php` reads the generator sources with comments stripped, the way the
role-separation tests read the middleware.

`random_int()` keeps exactly one job, in `Game::randomSeed()`: **choosing** a seed wants
unpredictability, **using** one wants repeatability. Mt19937 is the engine because its 32-bit seed is
the `Game::SEED_MIN`/`SEED_MAX` range already stored, so every seed a gamemaster can type maps to one
sequence.

Generators are pure — no models, no clock, no container — which is why they live in `tests/Unit`
(`tests/Pest.php` binds `RefreshDatabase` to Feature only, so anything reaching for a row fails
loudly). `App\Actions\Generation\*` is the half that writes rows.

## Runs are the record; only their artefacts are thrown away

`generation_runs` holds one row per invocation, **including rejected ones**, and its status is derived
from `accepted_at`/`superseded_at` the way `InvitationStatus` is derived. Regenerating **supersedes**
the pending run — the row and its seed survive, the locations or stelliums it wrote are deleted —
because the seeds that were tried are the debugging record, while only one cluster can be the game's
at a time. `attempt` counts superseded runs too, so "attempt 3" keeps meaning the third time somebody
asked.

The game's generation state is derived from those runs by `Game::generationStateFor()`; there is **no
`generation_stage` column**, and adding one would create a copy that can disagree with the rows it
summarises. A stage is `locked` until the previous stage is accepted, `ready`, `review` while a run is
pending, `accepted` after. `GenerationStage`'s declaration order *is* the dependency order — nothing
else encodes it.

Adding a stage to the enum makes every unfinished game incomplete again, because
`isGenerationComplete()` sweeps `cases()`. That is intended, and it is why the check does not name the
last stage.

## Where each refusal lives: 403 for the game's state, a message for the field

- **403** — the stage cannot run: the game has left setup, the previous stage is not accepted, there
  is nothing pending to accept, there is nothing to start over. None has a field to hang a message on,
  and no value would make it allowed. `GenerationController` aborts; the screen hides the control, and
  the hidden control is never the check.
- **Validation message** — the seed: out of range, or unchanged from the pending run's. Regenerating
  with the same seed would redraw the same thing, so it is refused with *"Choose a seed other than the
  one that produced this."* The rule exists only while there is a pending run to differ from, so a
  first run may reuse any seed, including one another stage used.

The same line splits the two seed refusals in [games.md](games.md), and it is the line to keep: **who
is asking → 403; what was posted → a message.**

Two consequences worth not re-deriving:

- **The base seed closes once any run exists** (`Game::hasGenerationRuns()`), with its own message
  telling the gamemaster to start over. Editing a number that has already been drawn from would change
  nothing that exists. Starting over opens it again.
- **A game cannot become `Active` until every stage is accepted** — a shared rule in
  `GameValidationRules::gameStatusRules()`, used by both areas' status requests, naming the missing
  stage. Only `Active` is gated: archiving a half-built game is ordinary housekeeping.

## Starting over is all-or-nothing, on purpose

`RestartGeneration` deletes every run for the game, and the cascade takes locations, stelliums and
stars with it. There is deliberately no per-stage rewind: a cluster and the stelliums standing on it
are one world, so re-opening the cluster while its stelliums survived would leave stars at locations
that no longer exist, and a per-stage rewind would need a second copy of the stage ordering to know
what to destroy. It is a `POST`, not a `DELETE`, because no route in the gamemaster area accepts
`DELETE` (see [gamemaster.md](gamemaster.md)).

## `Stellium` must name its own table

Laravel's inflector pluralises `Stellium` to **`Stellia`** — the `medium`/`media` rule — so:

- `App\Models\Stellium` sets `protected $table = 'stelliums';`
- `..._create_stars_table.php` passes `constrained('stelliums')`, because inference would point the
  foreign key at `stellia`
- a future `{stellium}` route parameter inside `Route::scopeBindings()` would need an explicit
  relation name, since scoped binding derives `Str::plural(Str::camel('stellium'))`

`tests/Feature/GenerationModelTest.php` asserts both the hazard and the override, so the day an
upstream fix makes the override unnecessary, somebody removes it deliberately rather than by accident.

## The algorithms, and the numbers behind them

**Cluster.** Dart throwing over the bounding cube: draw a point, reject the origin, reject anything
outside the sphere, reject anything within `MINIMUM_SEPARATION` of a location already placed. Uniform
over the lattice points inside the sphere *is* uniform by volume for integer coordinates — each point
owns one unit — so there is no centre bias, which sampling a radius and a direction would introduce.
All comparisons are squared, keeping it in exact integer arithmetic, so "at least 2" includes exactly
2.

Measured: 14,146 points are available, a full cluster takes a median of ~235 draws and leaves ~11,750
points still legal, and no failures in 2,000 seeds. `MAXIMUM_ATTEMPTS` (100,000) therefore guards a
*future* change of the constants — more locations, wider separation — where the generator must throw
`GenerationFailed` rather than spin. The O(n²) separation check is ~12,000 integer comparisons; a
spatial index would be pure cost.

**Stelliums.** The 70/20/9/1 distribution is a **quota, not a probability**: every game gets exactly
70 singles, 20 doubles, 9 triples and one quadruple, and the seed decides only which location gets
which. Rolling each stellium independently is the obvious alternative and is worse — the counts drift,
and the lone quadruple (the rarest thing in the cluster) is missing from roughly a third of games. The
quota is built from the percentages by **largest remainder**, so it keeps summing to the location count
if either the distribution or `LOCATION_COUNT` changes; at 100 every share is already whole, which is
why the percentages read as the counts today.

## Smaller decisions worth not re-litigating

- **One controller, three routes, stage as a route parameter.** `{stage}` binds to the enum, so an
  unknown stage 404s with no code and the planets stage adds no routes, no controller methods and no
  screen wiring. `StageGenerationRegistry` maps stage → action with a `match`, so adding a case without
  an implementation is a static-analysis error rather than a silently missing stage.
- **`RunGeneration` owns the transaction and the bookkeeping**; a `StageGeneration` implementation only
  writes and discards its own rows. A generator that throws must leave the game exactly as it was
  rather than with a run row claiming a cluster that was never written.
- **None of the generated models declares `#[Fillable]`.** Nothing about a generated world arrives from
  request input — the actions write the rows with bulk inserts — so there is nothing to open up.
  Factories run unguarded, which is how tests build these rows.
- **`locations.game_id` is redundant with the run's, and load-bearing anyway**: the unique keys on
  `(game_id, ordinal)` and `(game_id, x, y, z)` are guarantees about the *game*, and a regenerated
  cluster is a different run with the same guarantees.
- **Bulk inserts, so timestamps are set by hand.** A hundred model saves inside one request is a
  hundred round trips; a bulk insert does not run the model's timestamping, which is why `$now` is
  passed explicitly.
- **The whole cluster ships with the page.** A hundred locations of four small numbers is smaller than
  the request that would fetch them, and reviewing a cluster means looking at all of it. `star_count`
  is null until the stelliums exist — which is not the same as zero, since every location gets at least
  one star.
- **`suggested_seed` is a fresh draw on every render**, and only on the gamemaster's payload: the base
  seed for a first run, a new random number for a regeneration (which must differ from the pending
  one). The administrator's screen carries the same summary with no suggestions, because it has no
  control to put them in.
