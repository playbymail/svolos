# The home system every player begins in

Globs: `app/Generation/HomeTemplate.php`, `app/Generation/HomeTemplatePlanet.php`,
`app/Generation/HomeTemplateGenerator.php`, `app/Actions/Generation/GenerateHomeTemplate.php`,
`app/Actions/Generation/GeneratePlanets.php`, `app/Generation/PlanetGenerator.php`,
`database/migrations/*_add_template_to_generation_runs_table.php`,
`tests/Unit/HomeTemplateTest.php`, `tests/Unit/HomeTemplateGeneratorTest.php`,
`tests/Feature/Gamemaster/HomeTemplateTest.php`

Every player's faction begins in the **same** home system. A template settles what that system is, and
the two stages after it read it. Read [generation.md](generation.md) first — this is the third stage of
the machine described there, and every rule about *when* a stage may run applies here unchanged.

## Fair start, not identical start — the split is the whole feature

A home system's nine planets take their **type and habitability** from the template, so every player
looks at the same map and the same strategic picture. Their **deposits are still drawn**, per player,
so what a home is worth to mine differs. And the **home world alone** is settled completely, deposits
included, so nobody begins on a better planet than anybody else.

Those three sentences are the requirement, and each one is a way the feature can be broken:

- copying the whole template into a home system would make the deposits pointless;
- redrawing the types would give one player a better home than another;
- leaving the home world to be drawn would put the fairness back in the hands of the dice.

`tests/Feature/Gamemaster/HomeTemplateTest.php` asserts all three together against two seated players,
which is the test to keep if any of the others go.

## It is an input, so it lives on the run

`generation_runs.template` is a nullable json column, not a table. See
[generation.md](generation.md)'s "inputs are columns, artefacts get tables" — a template is a record of
what somebody *asked for*, like the seed, so a superseded template run keeps it. That is what lets the
screen name a document a gamemaster tried and rejected, and it is why `GenerateHomeTemplate` writes no
rows and its `discard()` is an empty method.

`file` inside that column is null for a drawn template and the original filename for an uploaded one.
That null is the **only** thing that distinguishes the two afterwards, and three things read it: the
toast, the history list, and the "different seed" rule below.

## Two classes, because one draws and one does not

`HomeTemplateGenerator` draws a template from a seed; `HomeTemplate` parses one out of an uploaded
document. They are separate so the purity test can hold them to different rules — the generator is in
`seededGenerators()` and must contain `SeededRandomizer::for`, while the parser is in
`generationSources()` and must merely never reach for randomness. `HomeTemplate` is the first entry to
sit on the second list and not the first; the reason is written where the dataset splits.

A parser that quietly filled a missing field with a random value would be the worst bug this subsystem
could have — a home that differed between players, which is the one thing the stage exists to prevent.

## The arrangement is fixed and only the numbers are drawn

`HomeTemplateGenerator::ARRANGEMENT` is nine planets in a fixed order and no seed changes it, which
inverts every other generator here on purpose: the *shape* of a home is a decision the game makes once.
The home world is ordinal **3** at habitability **25** — the top of the rocky dice, fixed — and what the
seed decides is the other eight habitabilities and the home world's three deposits. So games differ
from each other and players inside one game do not.

The dice come from `PlanetGenerator`'s public tables rather than being restated, so a retuned
habitability table moves a generated template with it. The **draw schedule** — eight habitabilities in
ordinal order skipping the home world, then the home world's fuel, metals and minerals — is
load-bearing in the way `PlanetGenerator`'s is: reordering it changes every generated template for a
given seed without changing the odds of anything.

## An uploaded document names its own home world

`"home": true` on exactly one planet, carrying that planet's three deposits. Not inferred from "the
most habitable", which would break on a tie and would silently move somebody's home world on a typo.

Two refusals are worth not re-litigating:

- **Deposits on a planet that is not the home world are rejected, not ignored.** They are drawn per
  player, so a document setting them is claiming control it does not have, and dropping them quietly
  would leave a gamemaster believing they had fixed their neighbours' mining.
- **The bounds are the column's (0–255), not the generator's (25 and 35).** A drawn planet is the
  outcome of a distribution and must stay inside it; a template is somebody's deliberate choice, and
  the honest limit on a deliberate choice is what the row can physically hold.

The size limit *is* the generator's, though: 1–10 planets, derived from `PlanetGenerator::PLANET_DICE`
rather than restated, because a home has to be a system the cluster itself could have contained.

Every refusal throws `GenerationFailed` with `$field = 'template'`, so the sentence lands beside the
file input. A message tested without that field is a message nobody would see in the right place, which
is why the unit tests assert both halves.

## The "different seed" rule is off whenever a document is involved — on **either** side

`GenerationRunRequest`'s `Rule::notIn([$pendingSeed])` rests on one premise: the same seed redraws the
same thing. A document breaks it in both directions, and `templateDecidedByADocument()` therefore asks
about the pending run as well as about the request:

| pending run | this request | rule |
| --- | --- | --- |
| anything | uploads a file | **off** — two documents under one seed are two templates |
| was uploaded | draws | **off** — that seed produced a *document*, not this |
| was drawn | draws | **on** — the seed is the whole input, and it would repeat |

The middle row is the one that is easy to miss by only looking at the request, and it has a test of its
own for that reason.

## Smaller decisions

- **The stage added no routes**, despite being the only one settled by a file. It is still run from a
  seed, and the document rides beside it the way `traveler` does — so the gamemaster area keeps its ten
  routes. [generation.md](generation.md) has the full reasoning.
- **Parsing happens in the controller, before the run is made**, so a file nobody could read leaves no
  run behind — an unreadable document is not an attempt at anything. Drawing happens in the *action*,
  because it needs the run's seed, which does not exist until the run does.
- **The checkbox starts unticked**, making upload the default: a game whose starting positions are
  decided in advance is the reason the stage exists, and drawing one is the fallback. The file input is
  disabled rather than hidden while it is ticked, so the layout does not move under the pointer, and a
  disabled input posts nothing — which is what `required_without:generate_template` expects.
- **`GeneratePlanets` sets the stellium back onto each star** as it flattens them. It needs
  `location_id` to tell home stars from the rest, and the inverse relation is not loaded by the eager
  load — reading it without this is 141 queries for something the loop is already holding.
- **The generator draws the home world's deposits and then they are overwritten.** `PlanetGenerator`
  does not know which orbit is anybody's home, and keeping its schedule at a uniform three rolls a
  planet is worth more than saving one roll.
- **`summaryEntries()` drops null values** rather than printing them. `String(null)` renders as the
  word "null" beside a label, which reads as a fault; null means the stage had nothing to record — a
  drawn template has no file, and a lone home has no neighbour to measure against.
