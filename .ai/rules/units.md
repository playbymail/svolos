# The opening position: entities and what they hold

Globs: `app/Enums/{UnitType,UnitCategory,Inventory}.php`, `app/Enums/EntityType.php`, `app/Generation/UnitHolding.php`,
`app/Generation/StartingUnits.php`, `app/Generation/Kit*.php`,
`app/Actions/Generation/GenerateUnits.php`,
`app/Models/Entity.php`, `app/Models/Unit.php`,
`database/migrations/*_create_entities_table.php`, `database/migrations/*_{create_assets_table,rename_assets_table_to_units}.php`,
`database/factories/{Entity,Unit}Factory.php`,
`resources/js/components/LocationSystemPanel.svelte`, `tests/Unit/UnitTypeTest.php`,
`tests/Unit/StartingUnitsTest.php`, `tests/Feature/Gamemaster/UnitsTest.php`

Read [generation.md](generation.md) first — this is the **sixth** stage of the machine described
there, and every rule about *when* a stage may run applies here unchanged. Read
[agents.md](agents.md) too: it settled what an entity is before one existed.

An **entity** is a colony or a ship: the only kind of thing that accepts orders. A **unit** is a
quantity of one kind of thing an entity holds, in one of three inventories. The stage puts a colony on
every player's home world and the ship that brought them into orbit above it, each with the units it
begins with.

## The words here are the glossary's, and the stage is the one exception

This was written as `asset`, `assignment` and `infrastructure`, and
[`docs/reference/glossary.md`](../../docs/reference/glossary.md) settled three different words for
the same three things: the countable thing an entity is composed of and holds is a **unit**, the
list it sits in is an **inventory**, and the inventory holding what the entity was *built from* is
**components**. The glossary is the authority — it says a term belongs there once it is settled,
whether or not anything implements it yet — so the code moved, not the language.

`2026_08_21_030637_rename_assets_table_to_units` is the rename: table, column, and the stored
`infrastructure` values, all in one migration, because a database that has done one and not the
others is broken either way round.

**`GenerationStage::Assets` is deliberately still called that.** Its backed value `'assets'` is
stored in `generation_runs.stage` and is a route parameter, so renaming it would orphan every stored
run and break saved URLs. Only its **label** moved, to "Units" — the same trade `Stelliums` makes
for the opposite reason. Label is display, value is identity; when you read `Assets` in the enum,
that is why.

## The catalogue is code, and the deciding question is who edits the numbers

`UnitType` is an enum rather than a `unit_types` table, and the reason is not how many kinds there
will eventually be. It is that the numbers are tuned in this repository, do not vary per game, and
no gamemaster sets them — that is a code shape, not a data shape. `PlanetType` and
`PlanetGenerator::DEPOSIT_DICE` are the same argument, and `DatabaseSeeder` is deliberately a
manifest that creates nothing.

What the enum buys over a table is the `match` with no `default` arm: adding a kind is a
compile-time error at every decision site, PHPStan sees the whole catalogue at level 8, and
`UnitTypeTest` sweeps `cases()` so a half-defined kind fails rather than ships. If it ever does have
to be data, every caller already reads through `UnitType`'s methods, so the enum becomes the seeder
and nothing else moves.

## Measures are integers at `UnitType::SCALE`, because capacity is a comparison

A structural unit weighs 0.5 MU. It is stored as `50`.

Capacity rules ask *does this fit*, and in floating point `0.1 + 0.2 > 0.3` is true — a colony
holding thousands of units meets that. Every mass and volume on the catalogue is therefore an
integer at `SCALE` (hundredths), `UnitType::format()` is the one place a stored measure becomes the
decimal a report prints, and a third decimal place is a change to `SCALE` and nothing else.

**Do not add a method returning a float.** The moment two of them are summed and compared the bug is
back, and it will present as a hold that is one unit short for no reason anybody can see.

## Two volumes, and the **inventory** picks

`assembledVolume()` and `disassembledVolume()` are both on the kind; which one applies is
`Inventory::usesDisassembledVolume()`, because the glossary puts that decision on the inventory and
not on the unit. **Cargo is the only inventory measured crated.**

`UnitType::volumeIn()` is what every capacity question should ask for, and it is what `Unit::volume()`
and `UnitHolding::volume()` call. Reading `assembledVolume()` directly is right only when the
question really is about the assembled state wherever the unit happens to be.

`UnitTypeTest` asserts the disassembled volume is never *larger* — equal is allowed, since raw ore
does not pack down, but larger would mean crating something made it bulkier, which the rules have no
way to mean.

## Thirteen categories, and `Infrastructure` is one of them

`UnitCategory` carries all thirteen with the definitions they were settled with, alphabetically —
there is no dependency between them, unlike `GenerationStage` whose declaration order *is* its
dependency order. `UnitCategory::types()` reads `UnitType::category()` rather than keeping a second
list, so the two cannot drift; `UnitTypeTest` holds that true in both directions.

**Nine of the thirteen are empty**, and that is a real state rather than a broken one — the
catalogue has kinds for Structural, Resource, Commodity, Propulsion and Infrastructure and nothing
for the rest yet.

Note what `Infrastructure` now is: **a category of units**, the installations that produce something
each turn. `Inventory::Components` was called `Infrastructure` until the day before this enum
arrived, and the rename was made for the glossary's sake rather than in anticipation. It happens to
have cleared the way — one word answering both questions would have been misread eventually. The
glossary's reserved-words section says so outright.

`Structural` is likewise the **category**; `Structure` and `LightStructure` are the two kinds in it.
The kinds carried the category's name until the table arrived, which is what
`2026_08_21_171834_rename_structural_unit_types` fixes.

## `EntityType` is four kinds, because a structural unit is measured by what it was built for

The glossary has named four entities since it was written — open air colony, enclosed colony,
orbital colony, ship — and the code carried two until the structural measures arrived. The split is
not cosmetic: the same structural unit encloses `TL²` VU in a hull, `TL² × 2` sealed on a
surface and `TL² × 10` under an open sky, and a single `colony` case cannot answer that.
`2026_08_21_175001_split_colony_entity_types` makes every existing colony an **open air** one,
because the one the expedition prepared has mines in the hills and fields cleared for farms.

`EntityType::structuralVolumeMultiplier()` returns 1, 2 or 10, and `UnitType::assembledVolume()` is
its only caller. A **multiplier rather than a divisor**, so the catalogue contains no integer
division and no measure can be quietly truncated. It lives on the entity because the entity is the thing that varies — the same reasoning
that put `usesDisassembledVolume()` on `Inventory`.

**Only `assembledVolume()` takes an `EntityType`; the crated measure does not.** A crate is a crate
wherever it is going. That asymmetry is the whole shape of the rule and is worth keeping visible.

`Unit::volume()` therefore needs its `entity` relation, and calls `loadMissing()` to be safe —
**eager-load `entity` when calling it over a collection.** `UnitHolding::volume()` takes the kind as
an argument instead, because a holding is written before an entity exists.

**Two of the four kinds start a game.** `StartingUnits::for()` answers an enclosed or an orbital
colony with an **empty kit** rather than a guess, and `entityTypes()` is the list of the two that do —
which now reads `EntityType::startingKinds()`, because `App\Generation\Kit` needs the same answer in
order to refuse an uploaded document that leaves one of them out, and two copies of "which kinds open
a game" would eventually disagree about the opening position.
Sweeps over kits must iterate `entityTypes()`, not `EntityType::cases()` — a case-driven sweep spends
half its runs asserting nothing, which is how PHPUnit's *risky* flag found a test whose only
assertion was comparing a value to itself.

## `measure()` is the only decimal in the catalogue, and `SCALE` is thousandths

`SCALE` has been hundredths and thousandths, and is **tenths**. The catalogue was rewritten so that
**a mass and an assembled volume are always whole**, and only a *crated* volume may be a fraction —
the smallest anywhere is a half. `UnitTypeTest` asserts that directly, so the scale cannot drift back
without a test failing first.

`format()` derives its decimal places from `SCALE` rather than hard-coding them. That was a real bug
found by moving the constant: it printed two places while the scale held three, so widening it would
have silently truncated every measure.

`UnitType::measure(0.005)` is the inverse of `format()` and the **only** place a decimal literal
appears. It exists so the catalogue reads like the sheet it came from: these numbers are the content,
and a transposed digit in `5` is invisible where one in `0.005` is not. The float is a literal
converted once — `round()` is what keeps `0.1 * 1000` off 99 — and nothing outside the class sees
one. **The rule against methods returning floats is unchanged.**

## A measure may be a function of the technology level

`mass()`, `assembledVolume()` and `disassembledVolume()` all take a technology level, and **every
call site must pass one**. `LifeSupport` is 8 × TL MU, 8 × TL VU assembled and 4 × TL VU crated; the structural kinds are
1 × TL by mass with a **squared** assembled volume. A higher level always means a
**more massive unit that does more** — that is the game's rule, not an accident of these two, so
expect the next kind to follow it.

Do not add a no-argument convenience overload. A kind whose measure varies would answer it wrongly,
and the caller that reached for it would never find out.

`UnitType::assertTechnologyLevel()` is the single definition of which levels a kind accepts, called
by each measure and by `UnitHolding`'s constructor. It lives on the enum rather than on the holding
because of the same dependency: `LifeSupport->mass(0)` would otherwise return **zero** and flow into
a capacity calculation as a unit that weighs nothing, which is a wrong answer rather than an error.

`UnitTypeTest` sweeps `LifeSupport` across the whole 1–10 range rather than checking one level,
because the arithmetic *is* the content — a transposed multiplier still passes at TL 1.

## `LIGHT_STRUCTURE_FACTOR` is the entire difference between STRC and STRL

The two structural kinds weigh the same, crate the same, and share one formula. A light structural
unit encloses **ten times** the room — thin walls holding more air per tonne. Set that constant to 1
and they become one kind with two names, which is why a test asserts the factor rather than the
numbers it produces.

The difference used to be the other way round: identical volume, and STRL a tenth of the mass. Same
idea, expressed in the measure that reads better on a report.

## Life support is a component, and the glossary said so first

`LifeSupport` sits in `[Components, Cargo]` beside the hull and the engines, never in operational.
That is not a new decision: the glossary has defined components as "the structure of its hull, its
engines, its **life support**, sensors and weapons" since it was written, well before the kind
existed. When a kind arrives, check whether the glossary already placed it.

## `Machinery` and `Supplies` are the two kinds still unplaced

Neither appears in the category table, neither has a report code, and neither reads unambiguously as
`Infrastructure`, `Commodity` or `Resource` from its name. So `category()` and `abbreviation()` both
answer `null` for them, and `hasTechnologyLevel()` is a **guess** — the only guess left, since
`CSGD`, `FOOD`, `FUEL`, `METL` and `MNRL` were given as having no level and the structural kinds
have one.

`UnitTypeTest` writes each of those lists out, so deciding one of these two is an edit against a
list rather than a hunt.

`CSGD` and `LSU` arrived with their measures on 2026-08-21 and are in the catalogue. The rule they
were held out under still stands for the next one: **a kind goes in with its numbers, not before**,
because a kind with no mass or volume fails the catalogue sweep as half-defined.

## Technology level is part of a row's identity, and `0` means "has none"

A unit is built at a technology level from 1 to 10, written into its report code: `STRL-10`. Most
kinds have one; the raw commodities do not, and those are shown as `FOOD` — **never `FOOD-0`**.

**The level is in the unique key**, `(entity_id, type, inventory, technology_level)`, because one
entity holds the same kind at several levels at once: a ship built with STRL-10 carrying crated
STRL-2 and running STRL-8 is three rows. Under the old key the second and third could not be written
at all, and the failure would have surfaced as a constraint violation inside a build order rather
than as anything naming the real problem.

**The absent case is `0`, not `NULL`, and the reason is that key.** SQLite — like most engines —
treats `NULL`s as distinct in a unique index, so a nullable column would take two
`(entity, food, cargo, NULL)` rows without complaint and break the single guarantee this table makes,
precisely for the bulk commodities where a duplicate row does the most damage. `0` is also the
*correct* value for those kinds rather than a placeholder: `UnitType::hasTechnologyLevel()` says
which are which, `UnitHolding`'s constructor refuses any other pairing in either direction, and
`reportName()` is what keeps the sentinel from ever reaching a reader.

`hasTechnologyLevel()` is settled only for the two structural kinds. The rest are answered by whether
they read as manufactured or as raw, and `UnitTypeTest` writes the split out as two lists so that
correcting one is an edit against a list.

## Renaming a table does not rename its indexes

`Schema::rename('assets', 'units')` and the `assignment` → `inventory` `renameColumn` both left the
unique index called **`assets_entity_id_type_assignment_unique`**, naming a table and a column that
no longer existed. SQLite carries an index's name through both operations unchanged.

That is not cosmetic. `dropUnique(['entity_id', 'type', 'inventory'])` derives the *conventional*
name, looks for `units_entity_id_type_inventory_unique`, and finds nothing — so the migration adding
`technology_level` failed halfway, after the column had been added and before the key was widened.
It had to drop the stale index **by name**.

If you rename a table here, rename its indexes in the same migration, or leave a comment saying you
did not. The index is now `units_entity_id_type_inventory_technology_level_unique`, which is what
Laravel would derive.

## Only the structural kinds are settled; the other nine are placeholders

`Structure` (STRC) and `LightStructure` (STRL) carry real numbers and real report codes.
`2026_08_21_164234_split_structure_units_into_structural_grades` is where the single `structure`
kind became the two, and every existing row became `light_structural` because
`StartingUnits` is the only thing that has ever written one — the colony's buildings and the ship's
hull, both light structural.

The other nine kinds are carried over from before there was a scale or a second volume: their
measures are the old single numbers times `SCALE`, with a disassembled volume of half the assembled
one. **They were sized against a structural unit weighing ten times what `Structural` now weighs**,
so treat them as provisional and expect them to move when their categories are settled.

`abbreviation()` returns `null` for all nine on purpose, rather than inventing a code that would then
be hard to change. `UnitTypeTest` spells out exactly which kinds are unnamed, so the list is visible
and shrinks; it also asserts no two codes collide, which is the failure that would make an order
ambiguous.

**There is no `UnitCategory` yet.** Structure *is* a category and the glossary records it as one with
its two types, but nine of the eleven kinds have no category settled, so an enum would be mostly
`null` arms. Add it when a second category is defined, not before.

## Control is a seat, and the arc stays dead

`entities.game_seat_id` is non-nullable and is the whole of control. [agents.md](agents.md) had
already ruled out the alternative by name: the `(Player | Agent) --o< Entity` exclusive arc existed
only because Player and Agent were separate tables, and here an agent is a `User` with `is_agent` set
holding an ordinary `GameSeat`. **Do not add an owning `user_id`, a polymorphic owner, or a second
nullable key.** Any of them re-creates the arc one level down.

A seat rather than an account because control is per-game while an account is not, and because seats
are retired rather than deleted — so an entity outlives its player leaving, which
`UnitsTest` asserts directly.

## `generation_run_id` is nullable, and that null is the only one in the schema

Every other generated table hangs off its run with a non-nullable key, because a location has no
meaning apart from the run that drew it. Entities are the first thing here that is **not purely an
artefact**: these were placed by the units stage, and a ship built during play will have been placed
by no run. The nullable column is what distinguishes the two, and it costs nothing — `discard()` is
still `$run->entities()->delete()` and starting over still takes them by cascade.

Nothing built in play can be lost that way: a game cannot leave setup until its generation is
complete, and `restart()` refuses any game that has. `EntityFactory` leaves the column **null by
default** so a test that wants a run-placed entity has to say `->for($run)` — the distinction stays
visible in the tests that turn on it.

## `inventory` is stored; `zone` is derived; the difference is who decided

A crated mine and a working mine are the same kind in two states, and moving between them is an act
somebody performs — so it is a column, the way `games.status` is. Contrast `planets.zone`, which has
no column because it is a function of the ordinal and the star's planet count and could only ever
disagree with them. Ask which one a new field is before adding it.

Which inventories a kind may sit in is a **rule**, and it lives on `UnitType::inventories()` /
`allows()`, enforced in `UnitHolding`'s constructor rather than by a check constraint. Only
`Structure` and `Engine` may be `Components`, because components means the frame and systems
of the entity itself; mines and factories are never components, because a colony's mine is a thing
it operates rather than a thing it is. `inventories()` is written case by case with **no `default`
arm** on purpose: a `default` would quietly give a new kind the commonest answer, and deciding where a
new kind may sit is the whole of adding one.

## One kit per game, and every player in it gets that kit

Every player in a game gets the same kit, down to the last tonne, because the alternative is that the
seed decides who begins ahead. That is the same fairness argument that makes every player's home
*world* identical (see [home-template.md](home-template.md)), and it is stronger here: the home
template's neighbours may differ because what a system is worth to mine is a thing to discover, while
what you are handed on turn one is not.

**Read that sentence carefully, because it says "in a game".** The rule is about **per-player**
variation. It used to be delivered by the stage drawing nothing at all — `StartingUnits` was the kit,
and every game in the world opened identically — and it is now delivered by drawing **once per
game**: `App\Generation\KitGenerator` produces one kit from the run's seed and `GenerateUnits` hands
it to every seat unchanged. That is exactly what `HomeTemplateGenerator` does for the home system
every player shares, and it leaves the fairness rule intact while letting two games differ.
[kit-templates.md](kit-templates.md) has the whole of it, along with the library a gamemaster keeps
their own kits in and the three ways one reaches the stage.

So:

- **`StartingUnits` is still on `generationSources()` and still not on `seededGenerators()`.** It is
  now the **baseline** `KitGenerator` jitters rather than the kit itself, which changes nothing about
  what it may do: it opens no stream, and being swept is what catches somebody reaching for
  `Arr::random()` to make the manifests "more interesting". `KitGenerator` is on **both** lists.
- **Only the quantities are drawn.** Which kinds, which inventories and which technology levels come
  from the manifests untouched — which is the line drawn two sections above, and it is what keeps the
  engines crated, the colony a city and the food out of proportion.
- **`GenerationRunRequest` still exempts the stage from the "choose a different seed" rule**, but
  through `redrawsFromTheRoster()` and no longer through `ignoresTheSeedEntirely()`. The old premise
  — that no seed gives anything different — is gone. What survives is the reason a gamemaster
  regenerates this stage at all: **the seats are part of its input**, so the same seed against a
  roster that has grown places genuinely different entities. That makes it the *same* kind of
  exemption as the home stellia's rather than the opposite one, and keeping the rule would mean
  seating a latecomer forced a seed change that redrew everybody else's kit.
- **The run records the seed, and now it means something.** It used to be stored the way `traveler`
  is stored on a stage that never reads it; it is now what the kit was drawn from.

## A player with nowhere to stand is skipped and counted, not a failure

Somebody seated after the homes were arranged has no home stellium and therefore no home world.
`GenerateUnits` skips them and reports `players_without_a_home` in the summary, so the review card
says it out loud rather than quietly placing fewer colonies than there are players.

It is **not** a `GenerationFailed`: there is no field on that form a gamemaster could change to fix
it, and the remedy is regenerating the homes. The hole is already covered elsewhere —
`Game::playersWithoutHomeStellium()` reports it and `gameStatusRules()` refuses to let such a game
become `Active` — so this stage's job is to be honest about it, not to invent a second gate.

## Structure *provides* volume; everything else *consumes* it

The assembled volume of a structural unit in `Components` is an entity's **capacity** — the room
inside it. It is not space the structure takes up, and it must never be added to what the entity is
carrying. A report shows it as *maximum capacity (volume)*, a number to fill; older games in this
family printed it as a negative and left the player to do the arithmetic, and this one does not.

Mass is the opposite: a structural unit's mass **is** part of the entity's total, because a ship's
engines read total mass to work out what it costs to move.

So an entity has two volume figures and one mass figure, and summing `Unit::volume()` across every
row gives none of them. **This is not modelled yet** — nothing computes capacity or free space — and
it is the piece a setup report cannot be written without.

## Volume is rounded up per holding, and the grouping is the row

**A part-used VU is a used VU.** `UnitType::roundUpToWholeVolume()` is applied by `Unit::volume()`
and `UnitHolding::volume()` after the quantity is multiplied in, so the rounding lands on the
*total*: fifty crated STRC-5 come to a whole 125 VU and pay nothing, forty-nine come to 122.5 and
occupy 123.

The grouping is inventory, kind and technology level — which is exactly the `units` unique key, so a
row *is* a holding and no separate grouping pass is needed.

**Per holding, never per unit.** Per unit it would be up to half a VU on every crate — fifty would
occupy 150 rather than 125 — which is a tax rather than the small penalty it is meant to be. It
exists to offset the gain from stowing, which is large: crating a structural unit takes it from `TL²`
VU to half a tonne's worth.

Adding two rows' volumes therefore adds two already-rounded numbers. That is correct — they are
separate holdings and each pays its own rounding.

## The colony's structure is oversized on purpose — do not trim it

Twenty STRL-10 enclose 200,000 VU, about **96%** of the starting colony's volume and far more room
than its people can fill. It reads like a number somebody fat-fingered. It is not.

The advance expedition built a **city**, sized for an armada that mostly never arrived, and the first
wave that did has vanished. Empty streets built for a population that is not coming is the premise
the game opens on. Shrinking the quantity to match the survivors would quietly delete that, exactly
the way moving the ship's engines out of cargo would.

**The food is out of proportion for the same kind of reason.** Six thousand of the colony's 8,530 MU
is food, thirty times the mass of the buildings holding it. That is damage to the ship rather than a
loadout anybody planned: what survived is not what the fleet's planners loaded. Do not "rebalance"
it into a tidy ratio.

The *measures* are settled and the *quantities* are content, so the numbers here may still be tuned —
but the colony having far more enclosed volume than it needs, and far more food than anything else,
are facts of the setting rather than bugs in the kit.

## The ship's engines are in the hold on purpose

`StartingUnits::ship()` puts `Engine` under `Cargo` and nothing under `Components` but the hull.
That is `docs/copy/player-introduction.txt` written as data: "The main engines are gone. Burned out
sometime during the voyage." A ship's ability to move will be read off its **components**, so
this ship cannot leave until somebody installs them. Moving those two units to `Components`
would undo the premise the whole game opens on without touching a line of rules code, which is why
`StartingUnitsTest` and `UnitsTest` both assert it.

The numbers in the manifests are content and are meant to be tuned. The *shape* — which inventory
each kind sits in — is not.

## Where it is reviewed, and why it has no screen of its own

The stage added **no routes**. What was placed rides on `locationDetail`, the optional prop the
planets are already reviewed through, because "what is at this system" and "what is standing in it"
are one question asked once — and because only a handful of the hundred locations have anybody at
them. Every planet carries an `entities` key, empty on the ones nobody is at: a screen that had to
tell "nobody is here" from "this payload predates the stage" would be reading a distinction the server
never meant to draw.

Units are ordered server-side by inventory and then by kind, so components — the part that says
what the thing *is* — reads first. `LocationSystemPanel` groups the list it is handed rather than
sorting it again: a second ordering could disagree with the first, and the one that would win is the
one nobody is looking at.

**That pairing has already broken once, and both halves of the fix are load-bearing.** The sort was
written as `sortBy([$closure, $closure])`, which silently sorts nothing — Laravel calls a callable
comparison as a full comparator, so a one-parameter closure returns a position where a comparison
belongs (see [php.md](php.md)). The interleaved list then reached a `{#each}` keyed on the group, and
`each_key_duplicate` left the panel showing its loading skeleton for ever with only a console error
to say why (see [frontend.md](frontend.md)). So:

- the server sorts with **one** closure returning `[inventoryIndex, typeIndex]`;
- the panel looks an inventory up across every group it has already made, so a duplicate key is
  impossible whatever arrives;
- `UnitsTest` asserts the **whole sequence** — that each inventory appears in one contiguous run,
  in the enum's order. A test asserting only that components comes first passes on an interleaved
  list, which is exactly how this shipped.

## What is deliberately absent

- **Orders.** Only entities accept them and the check belongs in a domain action both transports
  call, never in a controller — see [agents.md](agents.md), which is still owed that half.
- **Whether a *particular* ship can move.** `EntityType::isMobile()` is a fact about the kind. Fuel
  and installed engines are a rule, and no order asks it yet.
- **Individual units.** `(entity_id, type, inventory)` is unique and the row carries a quantity, so
  no unit can differ from its neighbour — no condition, no damage, no name. The day something needs
  that it wants a second table, not the exploding of this one.
- **`mass` and `volume` in the payload.** Both are functions of the kind and the quantity; shipping
  them would be a second copy of `UnitType` that could disagree with the first.
