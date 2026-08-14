# Generating a game's world

Globs: `app/Generation/**`, `app/Actions/Generation/**`, `app/Enums/Generation*.php`,
`app/Enums/PlanetType.php`, `app/Models/GenerationRun.php`, `app/Models/Location.php`,
`app/Models/Stellium.php`, `app/Models/Star.php`, `app/Models/Planet.php`,
`app/Http/Controllers/Gamemaster/GenerationController.php`,
`app/Http/Requests/Gamemaster/GenerationRunRequest.php`, `app/Concerns/PresentsGeneration.php`,
`database/factories/{GenerationRun,Location,Stellium,Star,Planet}Factory.php`,
`resources/js/components/GenerationStageCard.svelte`,
`resources/js/components/ClusterLocationsTable.svelte`,
`resources/js/components/ClusterHexMap.svelte`, `resources/js/lib/cluster-hex.ts`,
`resources/js/components/LocationSystemPanel.svelte`, `resources/js/types/generation.ts`,
`tests/Unit/*GeneratorTest.php`, `tests/Unit/GeneratorPurityTest.php`,
`tests/Feature/Gamemaster/GenerationTest.php`, `tests/Feature/GenerationModelTest.php`,
`tests/Pest.php`

A game's world is built in **stages**, by its gamemaster, while the game is in `Setup`: generate from
a seed, review, then accept or try another seed. Accepting unlocks the next stage. There are six, in
this order:

1. `Cluster` — 100 locations;
2. `Stelliums` — one star group per location, 141 stars in all;
3. `HomeStelliaTemplate` — the home system every player will begin in;
4. `HomeStellia` — which systems those are, one per player;
5. `Planets` — one to ten around every star, **except** a home system, which is copied from the
   template;
6. `Assets` — a colony on every player's home world and a ship above it, with what each begins
   holding.

Read [games.md](games.md) for the seed itself, [gamemaster.md](gamemaster.md) for the area's gate,
[home-template.md](home-template.md) for the third stage, [home-stellia.md](home-stellia.md) for the
fourth and [assets.md](assets.md) for the sixth.

**The last four are in that order because of a dependency, not a preference.** The planets stage used
to run third and draw every star alike; it moved because a home system is *copied* rather than drawn,
so it needs both the template to copy and the arrangement that says where to copy it. The assets stage
is behind it for the same kind of reason one step further on: a home stellium says which *system*
somebody begins at, and the world they stand on does not exist until the planets are written. Anyone
tempted to move the planets back should read the top of `GeneratePlanets` first.

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

Two things about that test are load-bearing:

- **`random(` is on the forbidden list and is not redundant with `rand(`.** The letters `rand` in
  `random(` are followed by an `o`, so the original list let `Arr::random()`, `Str::random()` and
  `$collection->random()` straight through — and a weighted pick is exactly where somebody reaches for
  `collect($weights)->random()`. It is written with the parenthesis so it does not also match
  `$randomizer->`. `new Randomizer` is there for the same class of hole: a randomizer built with no
  engine defaults to `Random\Engine\Secure`, unseeded. Never shorten it to `Randomizer`, which matches
  `SeededRandomizer`.
- **The dataset is split into `seededGenerators()` and `generationSources()`.** Only the first must
  contain `SeededRandomizer::for`. A helper extracted out of a generator *receives* a randomizer, so it
  would fail that assertion — and the tempting fix, calling `SeededRandomizer::for` inside the helper,
  is a real bug: it restarts the stream on every call and makes every planet in a game identical. That
  is why `PlanetGenerator` keeps `roll()` and `pick()` private rather than extracting them.

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
the pending run — the row and its seed survive, whatever it wrote (locations, stelliums or planets) is
deleted — because the seeds that were tried are the debugging record, while only one cluster can be
the game's at a time. `attempt` counts superseded runs too, so "attempt 3" keeps meaning the third time somebody
asked.

**Inputs are columns on the run; artefacts get tables.** That is the line to hold when adding
anything: `seed`, `traveler`, `minimum_separation`, `separation_in_hexes` and `template` are all
records of what somebody *asked for*, so they live on the run and survive being superseded, while
locations, stelliums, stars, planets and home stelliums are what a run *produced* and are deleted by
its `discard()`. `template` is the one that looks like it wants a table of its own — it is a list of
nine planets — and putting it in one would mean a superseded template run losing the very document
that identifies it, which is the thing a seed exists to be.

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

`RestartGeneration` deletes every run for the game, and the cascade takes locations, stelliums, stars
and planets with it — four levels deep now, which is why `GenerationModelTest` asserts the chain
reaches the end rather than stopping at the stars. The **home stellia** come off the same delete as a
*branch* rather than a fifth level: they hang straight off the run, and off a `game_seats` row that
must survive them. The **entities** are a second such branch, one level deeper — they hang off the run
and off both a seat and a planet, and their assets cascade from them, which is what
`GenerationModelTest` pins along with the fact that neither the seat nor the planet goes with them.
The dialog on the gamemaster's screen enumerates all of it, home stellia and the colonies and ships
included — that sentence *is* the confirmation, so it cannot be left describing only the cluster, and
what a gamemaster thinks of as *theirs* rather than as generated is the half most worth naming.
There is deliberately no per-stage rewind: a cluster and the stelliums standing on it
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

**The screen says "stellia" and the code says "stelliums", and that is not a drift to fix.** The
inflector is *right* about the word — `stellia` is the Latin plural, and it is what the game is played
in — so `GenerationStage::Stelliums->label()` returns `'Stellia'`, and with it every heading, accept
button, toast and status refusal on the gamemaster's screen. `StelliumPlan::summary()`'s key is
`stellia` for the same reason: `GenerationStageCard` prints a summary's keys verbatim, so that key *is*
a label.

What stays `stelliums` is everything that is an **identifier** rather than a word:

- the enum **case** and its backed value, because the value is stored in `generation_runs.stage` and is
  a route parameter — renaming it would orphan every stored run and break saved URLs;
- the table, the model, the relations and `Game::stelliums()`, per the override above.

Label is display, value is identity, and only one of the two is free. A test pins the refusal sentence
("the stellia stage has not been accepted yet") so changing the label back is a deliberate act.

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

*Traveler mode is **two more rejections**, not a second algorithm.* `generate($seed, traveler: true)`
also refuses a candidate sharing an `(x, y)` pair with one already placed. Separation cannot deliver
that and never could: two systems thirty apart in `z` are as far apart as the cluster gets and still
stand in the same column — which the hex map draws as one hex, since a cell *is* an `(x, y)` pair with
no binning step. So the constraint is exactly "one system per hex". 709 columns exist inside the
outline for 100 locations; measured over 300 seeds it never failed and took 191–301 attempts, a median
of 248 against the ordinary 235.

**The second rejection is the centre column, and `isOrigin()` is not it.** `(0, 0, -10)` is thirty
units from the middle of the cluster and is not the origin, but it shares the origin's `(x, y)`, so
the map draws it in the middle hex — the one presented as the cluster's empty centre. "One system per
hex" has to include the centre hex or the map contradicts itself, which is how this was found: seed
3332012312 put system #53 exactly there. `Coordinates::isInCentreColumn()` is the wider test, and
`ClusterGeneratorTest` keeps that seed as a named case beside the sweep.

**An ordinary cluster still occupies the centre hex about one game in six** — measured at 17.8% over
400 seeds — because only the origin *point* is refused without the flag. That is a live disagreement
with what a reader expects, and it is left alone on purpose: widening the rejection to every cluster
would change the draw for that share of seeds and break the clusters they are stored to reproduce.

So **the map says nothing about the centre at all**, in either direction. Its caption used to read
"the centre hex is always empty", which was wrong that often. Replacing it with a sentence that
reported which of the two it was is the obvious repair and was rejected: it spends a line of prose on
something the picture already shows, and the reader has to hold a claim in mind to check it. The
locations table's "The centre itself is always empty" is a **different and correct** claim — that one
is about the origin *point* — so it stays.

**Two invariants keep every already-accepted seed replayable, and both are easy to break:**

- **The three `getInt` calls stay unconditional and in order.** Every rejection is a bare `continue`
  with nothing drawn between, so with the flag off the candidate stream is the one the generator has
  always produced. `ClusterGeneratorTest` pins seed 4242's first three coordinates and its 253 attempts
  as **literals** for this reason — comparing two runs of the same code cannot catch a shifted stream,
  because it agrees with itself perfectly while every stored cluster quietly renumbers. Drawing a
  coordinate only once an earlier one passed is the tempting optimisation and is the bug.
- **The order of the rejections cannot change the output**, for the same reason: no test consumes a
  draw. The column test is placed ahead of the separation scan purely because it is cheaper.

The flag lives on the **run**, beside the seed, because it is an input to that attempt: a gamemaster
can try one seed both ways and the accepted run is the record of which cluster the game got. Only the
cluster stage reads it; a run of another stage stores what was asked and ignores it, which is why
there is no validation rule tying it to a stage — no value of it is ever wrong.

*`occupied_hexes` is a measurement, not an echo of the flag.* `LocationSet` counts the distinct
`(x, y)` pairs the way it measures the realised `minimum_separation` — after the fact, from the points
— so the summary states what the cluster *is* rather than what the generator was told to do, and the
two can never drift apart. It reads 100 beside `locations 100` under traveler and about 95 without.
The mode itself is shown from the run's own `traveler`, so nothing is stored twice.

**Stelliums.** The 70/20/9/1 distribution is a **quota, not a probability**: every game gets exactly
70 singles, 20 doubles, 9 triples and one quadruple, and the seed decides only which location gets
which. Rolling each stellium independently is the obvious alternative and is worse — the counts drift,
and the lone quadruple (the rarest thing in the cluster) is missing from roughly a third of games. The
quota is built from the percentages by **largest remainder**, so it keeps summing to the location count
if either the distribution or `LOCATION_COUNT` changes; at 100 every share is already whole, which is
why the percentages read as the counts today.

**Planets.** Each of the 141 stars gets 3d4 − 2 planets — one to ten, averaging 5.5, so **775.5
expected planets a game** and 1,410 at the very most. They are numbered outward, and a planet's
position in its system decides what kind of thing it is.

*Except a star somebody begins at.* `generate()` takes a third argument naming those by their index in
the star count, with the planets the game's template settled. Those systems skip the count roll and
the type and habitability rolls and **draw only their deposits** — which is exactly what a home is
allowed to differ in, and why the parameter is a list of planets rather than a flag. It comes off the
same single stream in star order, so naming a home changes what the stars *after* it get; that is the
correct behaviour and a test pins it. Two things follow that are easy to trip over: a given seed
produces different planets than it did before the parameter existed (a deliberate break, taken when
the stages were reordered), and the generator still never learns what a "home" is — it is handed a
list and an index, and `GeneratePlanets` is what knows the rest.

*The zones come from our own solar system, and the arithmetic is integer.* Of nine bodies, orbits 1–4
are rocky, 5 is the belt, 6–7 are gas giants, 8–9 are ice giants — so `ZONE_BOUNDARIES` is `[4, 5, 7]`
out of `SOLAR_SYSTEM_ORBITS`, and a star with nine planets reproduces that arrangement exactly. A
planet sits at the fraction `(2o − 1)/(2N)`, and `(2o − 1)/(2N) < k/9` is rearranged to `9(2o − 1) < 2kN`
for the same reason `ClusterGenerator` compares squared distances: a boundary that has to fall one
specific way must not be decided in floating point.

*The zones are not equal, and small systems do not reach all four.* The belt is one ninth wide, so any
star with fewer than nine planets skips a zone, and which one depends on how many it has. Two of the
consequences read like bugs and are not — **a lone planet lands in the belt** (the midpoint of its
system) and so is usually an asteroid field, about two stars a game; and a **three-planet system has no
outer zone at all**, so gas giants are rare around those. `PlanetGeneratorTest` pins the whole table
for N = 1…10 precisely so nobody re-derives it and "fixes" it.

Weighting the zones by how many planets land in them across the whole 3d4 − 2 distribution gives
**inner 45.2%, belt 9.7%, outer 22.4%, far 22.7%** — 159 / 34 / 79 / 80 slots out of 352 per 64 stars,
not four quarters. `ZONE_WEIGHTS` is tuned against *those* shares, which is why the rows do not
themselves look like the solar system's proportions even though what comes out of them does: measured
over the test's seeds the mix lands at **44 / 11.2 / 22.8 / 22 against a target of 44.4 / 11.1 / 22.2 /
22.2**. The boundaries do nearly all of that work; the weights only sharpen it.

*A probability here, where the stelliums were a quota* — and the section above is exactly what will
make somebody change it back. **A quota is incompatible with zoning:** the whole point is that where a
planet sits decides what it is, and dealing out predetermined type counts would overrule that. It is
safe here where it was not there because there are ~775 planets rather than 100 locations, so the mix's
standard deviation is about a percentage point and nothing rare can vanish the way the lone quadruple
stellium would.

*Habitability is per type, and asteroids are zero dice.* Rocky is 5d6 − 5, which spans exactly the
declared 0–25: the top of the scale belongs to the type that deserves it, and a dead rock stays
possible — a table with a positive floor would quietly make every rocky world habitable. Icy is
2d6 − 2, gas giants 1d6 − 1, and asteroids are `[0, 6, 0]`, so "an asteroid field is never habitable"
is a row in the table rather than an `if` in the code. About ten worlds a game clear
`PlanetProfile::HABITABLE_FROM`.

*Asteroids reach 35 in metals and minerals where nothing else passes 24, and that is the trade.* They
are the one type that can never be lived on, so they are reliably the richest thing to mine, with a
floor of 10 rather than a chance of nothing. Habitability and extraction pull against each other on
purpose: raising an asteroid field's habitability off zero and capping its deposits at everything
else's ceiling are the same mistake from either end, and one test asserts both halves together for
that reason.

*The draw schedule is variable, and cannot not be.* Type is drawn first and the dice that follow belong
to the type that came up — five for a rocky world's habitability, none for an asteroid field's. Still
entirely determined by the seed, but it means retuning **any** weight shifts every draw after it, so a
regenerated world differs everywhere rather than only where the change applies. `pick()` also walks a
weight table in insertion order, so reordering the keys of a row changes the world a seed produces
without changing the odds of anything.

## The hex map shows *placement*, because shape is the same every seed

`ClusterHexMap.svelte` draws the cluster the way a strategic star map does — regular hexagons, each
system in the hex its `x, y` falls into, `z` printed beside it as a number. The obvious reason to
build it is the wrong one, and it is worth writing down so nobody redesigns it around that reason.

**There is nothing to judge about a cluster's spread.** The generator is uniform by volume and the
star mix is a quota, so every seed yields 100 well-separated locations and exactly 70/20/9/1
stelliums. A picture that answered "is this well spread" would give the same answer forever. What
actually changes with the seed is **where the rare stelliums landed and what is within reach of what**
— so the rare stelliums are made findable by size and colour, and selecting a system turns the readout
into a rangefinder.

**The middle hex is lit, not labelled.** It carried a crosshair and the word "centre" until that text
was seen colliding with a neighbouring system's height caption — the middle of the map is where
systems crowd most, so it is the worst place to put a word. It is now a blurred copy of the hex
outline under a crisper one, in `--space-ink`: findable at a glance, nothing to read, nothing to
overlap. It is worth marking at all only because every distance in the readout is measured from it.

**The caption under a hex is the system's height and nothing else.** The ordinal is an identifier
rather than a measurement — it says nothing about where a system sits — and the readout already gives
it on hover or focus. An earlier version printed `#51 (+12)` for triples and the quadruple while
everything else showed a bare height; that made one caption mean two different things depending on the
mark above it, and restated in text what the mark's size and colour already say. Do not add it back
without a reason that survives both of those. That is also why the form is a hex map rather than a scatter: apparent
distance is something you count off the picture, and `√(L² + Δz²)` puts the flattened dimension back.

**A hex holds up to four systems, and the map must keep saying so.** A location is unique on
`(game_id, x, y, z)`, not on `(x, y)` — measured over simulated clusters about **seven systems a game
land in a hex somebody already holds, and as many as four can stack**. `groupByHex()` therefore
returns a *list* per hex, a stacked hex draws an offset outline with a `2×` caption, and clicking one
steps through its occupants because no click position can distinguish them. Collapsing this to one
system per hex is the tempting simplification, and it would look right on ninety-odd hexes while
silently hiding the rest.

**Traveler mode is not a reason to revisit that.** A cluster generated with the flag really does put
one system in every hex, so a traveler game exercises none of the stacking code — but the flag is
**off by default and per run**, so ordinary clusters are still the common case and still stack. A map
rewritten around traveler clusters would be wrong for every other game, and wrong in the quiet way:
right on ninety-five hexes, hiding five. `ClusterGeneratorTest` keeps a test asserting that an
ordinary cluster occupies *fewer* hexes than it has locations, so the day that stops being true is a
deliberate discovery rather than a stale assumption.

Three smaller things that are load-bearing:

- **`Math.abs` in `toCube()`.** `-3 % 2` is `-1` in JavaScript, so the raw remainder shears the
  negative-`x` half of the map down a row. Every distance *within* one half stays correct and every
  distance *across* the centre is wrong, which is exactly the failure a glance does not catch — which
  is why `cluster-hex.test.ts` exists and why one of its tests requires the six cells `hexCentre()`
  *paints* touching a hex to be exactly the six `hexDistance()` *calls* adjacent. Pin new geometry
  that way rather than by example: the property catches parity bugs nobody thought of.
- **The selection lives in `pages/gamemaster/games/Show.svelte`**, not in the map or the table. One
  `locationDetail` prop comes back, so two owners would fetch over each other; the map and the table
  are two views of one open location. That is also why `ClusterLocationsTable` now takes `expanded`,
  `loading` and `onToggle` as props instead of holding them.
- **A hex's marks are judged over *all* its occupants, never `hex.locations[0]`.** The home glow is the
  live case: the primary is merely the lowest ordinal, so keying off it would leave a home unlit
  whenever another system shares its hex — right on ninety-odd hexes and quietly wrong on the rest,
  which is the failure this section is about. `some()` over `hex.locations`.
- **The centre hex's filter is now `cluster-hex-glow` and is shared.** A `<filter>` is a reusable
  resource — `filterUnits` defaults to `objectBoundingBox`, so the region is measured against whichever
  element references it — which is why one definition serves the centre and every home hex, and why it
  must stay outside the `{#each}`, where the id would stop being unique.
- **The map's palette is read as `var(--space)` / `var(--stellium-N)` / `var(--home)`, never
  `var(--color-*)`.**
  `@theme inline` *inlines* a token into the utilities Tailwind generates rather than emitting a
  `--color-*` custom property, so a hand-written `var(--color-stellium-3)` in an SVG resolves to
  nothing and the mark paints black. Those tokens are also deliberately **not** overridden in `.dark`:
  a star map is a picture, and it stays deep space in both appearances. `--stellium-1` to `-4` are an
  **ordinal** ramp — one hue, ascending lightness for a star count of 1 to 4 — not the categorical
  `--chart-*` set, which encodes identity and would throw away the order. The steps were fitted until
  they passed monotone lightness, an adjacent gap of 0.06 and contrast against `--space`; the legend
  is the required relief for the dimmest step and is not optional.

## Smaller decisions worth not re-litigating

- **One controller, three routes, stage as a route parameter.** `{stage}` binds to the enum, so an
  unknown stage 404s with no code, and neither the planets stage nor the home stellia nor the template
  added a route, a controller method or any screen wiring. `StageGenerationRegistry` maps stage →
  action with a `match`, so adding a case without an implementation is a static-analysis error rather
  than a silently missing stage.

  **The template stage is the one that nearly broke this, and did not.** It is settled by a *file*,
  which looks like a reason for an endpoint of its own; it is not, because the stage is still run from
  a seed and the document rides beside it exactly as `traveler` rides with the cluster. `store()` just
  takes a multipart request. The gamemaster area therefore still holds **ten** routes and
  `GameManagementTest`'s sweep passes untouched — worth knowing before adding an eleventh for the next
  stage that carries something bulky.
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
- **The whole cluster ships with the page. The planets do not.** A hundred locations of four small
  numbers is smaller than the request that would fetch them, and reviewing a cluster means looking at
  all of it. That reasoning does not survive ~775 planets of eight fields, and reviewing a seed means
  looking at a system or two rather than reading all of them — so `locationDetail` is an
  `Inertia::optional()` prop that only runs on a partial reload, asked for a location at a time. **No
  new route**: it is the same screen, asked a narrower question, and the location arrives as a query
  parameter — which is why the presenter scopes it to the game by hand, since `Route::scopeBindings()`
  only covers route parameters.
- **`star_count` and `planet_count` are both null before their stage, for different reasons.**
  `star_count` is null exactly when the stellium row is missing, so a nullsafe read answers it. A
  stellium *exists* before its planets do, so `withCount` returns a genuine `0` in the state that means
  "not decided yet" — reading that zero as null works only by the accident that every star gets at
  least one planet. `planet_count` is therefore gated on the run existing. Neither null means "empty".
- **The insert is chunked at 500 because of a binding ceiling, not a memory one.** SQLite's
  `MAX_VARIABLE_NUMBER` is 32,766 and a planet binds ten columns, so one statement holds 3,276 rows.
  Today's 775 fit and even the 1,410 maximum fits — but only while the constants stay put, and Laravel
  does not chunk inserts for you. Two statements inside the transaction `RunGeneration` already owns is
  the cheap way not to re-derive that.
- **`planets` has no `game_id` and no `zone` column.** `locations` carries a `game_id` because its
  unique keys are guarantees about the *game*; a planet's only uniqueness is `(star_id, ordinal)`, so
  the column would be a pure duplicate of a path already walkable through the stars. Zone is a function
  of `ordinal` and the star's planet count, so a column could only ever disagree with them — derived
  where needed, like `Location::radius()`.
- **`GenerationStageCard`'s `summaryEntries()` spreads any nested summary value generically**, and
  names only `mix` as a special case. The star mix is keyed by bare numbers and would read as "1 342"
  without the noun; a stage whose keys already say what they are — the planets stage's `types` — needs
  no entry here. The `{#each}` is keyed by the summary's own path rather than by the label, because
  Svelte throws on duplicate keys and a top-level `rocky` beside a nested `types.rocky` would collide.
- **`suggested_seed` is a fresh draw on every render**, and only on the gamemaster's payload: the base
  seed for a first run, a new random number for a regeneration (which must differ from the pending
  one). The administrator's screen carries the same summary with no suggestions, because it has no
  control to put them in.
