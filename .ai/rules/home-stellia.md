# Where each player begins

Globs: `app/Generation/HomeStelliumGenerator.php`, `app/Generation/Coordinates.php`,
`app/Actions/Generation/GenerateHomeStellia.php`, `app/Models/HomeStellium.php`,
`database/migrations/*_create_home_stelliums_table.php`,
`database/migrations/*_add_minimum_separation_to_generation_runs_table.php`,
`database/factories/HomeStelliumFactory.php`, `resources/js/lib/cluster-hex.ts`,
`tests/Unit/CoordinatesTest.php`, `tests/Unit/HomeStelliumGeneratorTest.php`,
`tests/Feature/Gamemaster/HomeStelliaTest.php`

Every player's faction starts somewhere: a single-star system, a set number of hexes clear of every
other. Read [generation.md](generation.md) first — this is the fourth stage of the machine described
there, and every rule about *when* a stage may run applies here unchanged and is not repeated.

## It is a **stage**, which is why it added no routes

The obvious build is a screen of its own: pick a player, click a hex, confirm, repeat. It was designed
that way first and replaced, for two reasons.

The first is that hand placement is a poor use of anybody's attention. The cluster's footprint is ~709
hexes, a placed home excludes the 61 within 4 of it, and only 70 of the 100 systems are single-star —
so greedy placement paints itself into corners, and six homes chosen badly leave a seventh player with
nowhere legal even though a different six would have left room. Solving that by hand is a puzzle
nobody asked for; a generator solves it by construction, and the **editable minimum separation** turns
"no arrangement exists" from a dead end into a dial.

The second is everything the stage machine already does. Seed, auto-incrementing attempt, review,
accept, regenerate, and an all-or-nothing start-over that takes the arrangement with it — all of it
came free, and `isGenerationComplete()` sweeps `cases()`, so the game cannot leave setup without this
stage with **no line of code**. `GenerationStage`'s docblock already promised the payoff and the price:
adding a case makes every unfinished game incomplete again, deliberately.

Two consequences worth stating so nobody looks for the missing piece:

- **There is no `HomeStelliumController` and no home stellia route.** `{stage}` binds to the enum, so
  the three existing generation routes serve it. The gamemaster area still holds exactly **ten**
  routes, and `GameManagementTest`'s sweep passes unchanged — that sweep is now also what keeps the
  disabled template-upload block on the screen inert.
- **Nothing about this is a fifth item on [gamemaster.md](gamemaster.md)'s list** of things a
  gamemaster may not do. That list is about the *requester*; the refusals here are the stage machine's
  ordinary ones about the game's state, and an administrator would be refused identically.

## The arrangement belongs to the **run**, not to the seat

`home_stelliums` is `(generation_run_id, game_seat_id, location_id)`, with both unique keys scoped to
the run — one home per player and one player per system, *within an arrangement*, so a fresh attempt is
written beside the pending one it supersedes rather than colliding with it.

A `home_location_id` column on `game_seats` is the tempting shape and is wrong. A seat is **roster**: it
outlives every world the game generates, and retiring one is a fact about a person rather than about a
cluster. An arrangement is a **generated artefact**, like the stelliums and the planets, so hanging it
off the run is what makes regenerate and restart work by the same mechanism everything else uses —
`discard()` is one line and the restart wipe is a cascade rather than code somebody has to remember.

`game_seat_id` is `cascadeOnDelete` and the seat is **not** a casualty of the run being deleted; the
model test asserts both halves together, because a mistaken cascade the other way would satisfy a
"the home is gone" check by deleting the player's place at the game.

It points at a **location**, not a stellium: the coordinates the roster prints and the hex distance the
rule measures both live there, and "single star" is a constraint the generator *draws under* — it is
handed only single-star systems — rather than something a foreign key could express.

**`HomeStellium` must name its own table.** The inflector pluralises it to `home_stellia`, the same
`medium`/`media` rule that forces `Stellium` to set `stelliums`. `GenerationModelTest` asserts the
hazard beside the override so an upstream fix is a deliberate discovery.

## The seed folds in the **attempt**, here and nowhere else

`GenerateHomeStellia` seeds its stream with `($run->seed + $run->attempt) % (Game::SEED_MAX + 1)`.

Every other stage seeds from `$run->seed` alone, because a stored seed has to keep meaning one fixed
world — feed it back and the same cluster comes out. Doing this anywhere else would renumber every
cluster already stored against a seed. Here it is the point: pressing Generate again **without touching
the seed** asks for another arrangement of the same world, which is the entire interaction.

**So `GenerationRunRequest` switches off `Rule::notIn([$pendingSeed])` for this stage**, through
`redrawsFromTheAttempt()`. That rule exists because regenerating with the same seed *would redraw the
same thing* — false the moment the attempt is in the stream — and leaving it on would forbid the one
gesture the stage exists for. Removing it everywhere instead would let a gamemaster press a button that
genuinely does nothing on the other three. The screen's hint text and its button label branch on the
same distinction.

The run stays exactly reproducible: `attempt` is stored on it. `seed + attempt` is not a way of being
random, it is a way of **indexing the arrangements of one seed**. The modulo is for a seed near
`SEED_MAX`.

**`presentStage()`'s `suggested_seed` has a third case because of this**, and it was found by running
the screen rather than by reading it: a regeneration of any other stage suggests a *fresh random*
number, since the same one would redraw the same thing — but doing that here contradicted the form
beside it, which says the same seed is fine and labels the button "try another arrangement", and it
would have quietly changed the world the arrangement is drawn from. This stage suggests the pending
run's **own** seed.

## The separation is a number **and a unit**, and they travel together

Two columns on `generation_runs`, both beside `traveler` and both behaving identically to it: inputs to
*that* attempt, stored so the accepted run records what the game got, carried back so trying again
keeps them, read by exactly one stage, and therefore **tied to no stage by any validation rule** — no
value of either is ever wrong, only irrelevant.

- `minimum_separation` — how far apart, as a bare number.
- `separation_in_hexes` — what that number counts. **Unset is the default and means Euclidean**: the
  straight line through all three dimensions, the same measure `ClusterGenerator::MINIMUM_SEPARATION`
  compares. Set, it means steps on the plane the map draws, `Coordinates::hexDistanceTo()`, which
  ignores height entirely.

**These are different questions, not two scales of one**, which is why it is a choice and not a
conversion. Two systems sharing a column are the *same hex* however far apart they are vertically — up
to thirty units — so they are zero apart by one measure and well clear by the other. A game where what
matters is how far a fleet must travel wants the first; one where what matters is how much of the map
lies between two players wants the second. `HomeStelliumGeneratorTest` pins that disagreement directly,
with one pair that passes Euclidean and fails hexes.

Three consequences that are easy to break:

- **The Euclidean comparison is squared on both sides**, exactly as `ClusterGenerator` does it. The
  coordinates are integers, so "at least 5" is decided in integer arithmetic rather than by how
  `sqrt()` rounds — a 3-4-5 triangle must not be refused. A test pins that boundary.
- **`isFarEnough()` and `separationBetween()` are the only two places the units are read.**
  `GenerateHomeStellia` measures the realised separation through the second, so the summary's two
  numbers are always in the same unit as each other. Measuring it one fixed way would print a hex count
  beside a Euclidean minimum for half of all runs, which reads as an arrangement that broke its own
  rule.
- **Every label that prints the number prints the unit.** The form's label swaps between "(distance)"
  and "(hexes)" off a *writable* `$derived` bound to the checkbox, and the review `<dl>` shows the unit
  in **both** states — unlike the traveler flag, which is only worth showing when set. A flag can be
  omitted when false; a unit never can, because the number beside it is ambiguous without it.

One bound serves both units: the ceiling is the cluster's **diameter**, which is 30 read either way —
the sphere's radius is 15 and so is the hex disc it casts.

## The hex metric exists twice, on purpose

`Coordinates::hexDistanceTo()` in PHP and `hexDistance()` in `resources/js/lib/cluster-hex.ts` are the
same function: the server places against it, the client draws against it. Both carry `abs()` on the
parity term, because `-3 % 2` is `-1` in **both** languages and the raw remainder shears the negative-`x`
half of the map down a row — every distance within one half stays right while every distance across the
centre goes wrong.

Two implementations is a drift risk, so it is pinned two ways:

- a **property** on each side strong enough to determine the metric uniquely — every cell has exactly
  six neighbours, and in TypeScript those are exactly the six `hexCentre()` paints as touching;
- a **literal table of eight pairs** straddling the parity boundary, duplicated verbatim in
  `tests/Unit/CoordinatesTest.php` and `cluster-hex.test.ts`, each naming the other. A property both
  sides satisfy says each is internally sound, never that they agree on a number. The two tables move
  together, the same device `ClusterGeneratorTest` uses for seed 4242.

## Failure is an ordinary outcome, so it is a message on the field

The generator does randomised greedy placement with restarts — place, drop everything now too close,
and throw the whole attempt away when it runs out of room. Restarts rather than back-tracking: that
would need a second copy of the placement order to unwind, where a restart is a few dozen draws.

Unlike every other generator here, `GenerationFailed` is reachable in **ordinary use** — eight players
twelve hexes apart is a request the cluster cannot satisfy, and no seed will change it. So
`GenerationFailed` now carries a `$field`, and `GenerationController::store()` turns the throw into a
`ValidationException` on it. The message names the separation **in the unit that was asked for**,
because that is the number on the form the gamemaster is looking at. That is the house line held, not bent: *a route-bound model's state is a
403, a posted value is a message* — the separation asked for is what has to move.

The `$field` lives on the **exception**, not in a `match` on the stage in the controller, which would
be a second copy of knowledge the failure already carries. The transaction in `RunGeneration` has
already rolled back, so nothing is written.

## Smaller decisions worth not re-litigating

- **Active players only, ordered by `id`.** A gamemaster runs the game rather than playing it and a
  retired seat has left, so neither is placed — and the roster's em dash for them is the truth rather
  than a gap. The order is arbitrary but must be *fixed*: the generator returns placements in draw
  order and the pairing is positional, so an unordered query would make the run unreproducible.
- **`singleStarLocations()` returns a list, not a collection.** The generator returns indices into it.
  A collection with gaps in its keys would pair players to places wrongly and silently.
- **`realised_separation` is measured from the arrangement**, the way `LocationSet` measures the
  cluster's own — so the card states what the arrangement *is* and the two numbers cannot drift. It is
  null below two homes, where the nearest neighbour does not exist, which is not zero.
- **Zero players is an ordinary state.** The stage runs, places nothing, and is acceptable; the
  generator returns before building a randomizer so it consumes no draw.
- **The map's home glow reuses the centre hex's filter**, renamed `cluster-hex-glow`. A `<filter>` is a
  reusable resource — `filterUnits` defaults to `objectBoundingBox` — so one definition serves both and
  it must stay outside the `{#each}`, where the id would no longer be unique.
- **`--home` is a new token with a different hue, not `--stellium-4`.** That ramp is *ordinal* and means
  a star count, so painting a one-star home in its top step would make the map contradict its own
  legend. Like the rest of the map palette it is read as `var(--home)` and is deliberately not
  overridden in `.dark`.
- **The glow is judged over all of a hex's occupants**, never `hex.locations[0]`. Up to four systems
  share a hex, so keying off the primary would leave a home unlit — right on ninety-odd hexes and
  quietly wrong on the rest, the failure [generation.md](generation.md) says the map must keep
  avoiding. The locations table and the roster both name the home in text for the same reason the star
  legend is not optional: the glow must never be the only channel.
- **The template-upload block is inert UI** — no route, no controller, no column. It says in the
  interface that placing homes from a prepared file is where this is going, and the route sweep is what
  keeps it from quietly acquiring an endpoint.
