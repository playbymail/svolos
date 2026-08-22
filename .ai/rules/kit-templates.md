# A gamemaster's library of opening kits

Globs: `app/Generation/Kit.php`, `app/Generation/KitEntity.php`, `app/Generation/KitGenerator.php`,
`app/Models/KitTemplate.php`, `app/Http/Middleware/EnsureUserRunsAGame.php`,
`app/Http/Controllers/Gamemaster/KitTemplateController.php`,
`app/Http/Requests/Gamemaster/KitTemplate*.php`, `app/Concerns/KitValidationRules.php`,
`app/Concerns/PresentsKits.php`,
`database/migrations/*_create_kit_templates_table.php`,
`database/migrations/*_add_kit_to_generation_runs_table.php`,
`database/factories/KitTemplateFactory.php`,
`app/Http/Middleware/HandleInertiaRequests.php`,
`resources/js/pages/gamemaster/kit-templates/**`, `resources/js/components/KitEditor.svelte`,
`resources/js/components/AppSidebar.svelte`, `resources/js/types/kits.ts`,
`resources/js/types/auth.ts`, `tests/Unit/Kit*Test.php`,
`tests/Feature/Gamemaster/KitTemplateTest.php`, `tests/Feature/AppShellTest.php`

A **kit** is what every player in one game begins holding: a colony's worth of units and a ship's
worth. Read [units.md](units.md) first — it is the stage this feeds, and every rule about the
catalogue applies here unchanged — then [generation.md](generation.md) for the machine and
[gamemaster.md](gamemaster.md) for the area's two gates.

`/gamemaster/kit-templates` is where somebody who runs a game keeps their own: draw one from a seed,
edit its holdings, download it, upload one back. The units stage then takes a kit three ways —
drawn, chosen from the library, or uploaded.

## The fairness rule was refined, not overturned

`StartingUnits` used to be the kit, and its docblock says the units stage draws nothing because "the
alternative is that the seed decides who begins ahead". **That argument is about per-player
variation and it is untouched.** A kit is settled **once per game** and handed to every seat
unchanged; what the seed now varies is what *this game's* opening is — exactly what
`HomeTemplateGenerator` does for the home system every player shares.

So the rule to hold is: **one kit per game, identical for every player in it.** Anything that gives
two players in one game different holdings breaks the thing this was built around, and
`UnitsTest` asserts it across players rather than against a hard-coded list.

Two things follow that read as contradictions of `units.md` until you have the sentence above:

- `KitGenerator` is on **`seededGenerators()`** — the first entry there whose existence looks like a
  violation of a rule the neighbouring file states. `StartingUnits` stays on `generationSources()`
  only, because it is now the **baseline** the generator jitters rather than the kit itself, and it
  must still never open a stream.
- the units stage is still **exempt from the "choose a different seed" rule**, but for a new reason.
  `ignoresTheSeedEntirely()` is gone; `redrawsFromTheRoster()` replaces it. The old premise was that
  every seed produced the same kit, and that is no longer true. What survives is the reason a
  gamemaster actually regenerates this stage — **a roster that has grown since it ran.** The seats
  are part of this stage's input, so the same seed against a different roster places genuinely
  different entities. Keeping the rule would break exactly that repair: seating a latecomer would
  force a seed change, which would redraw the kit every other player already has.

## Only quantities are drawn, and the shape is the setting

`KitGenerator` jitters each quantity by `VARIATION` percent around `StartingUnits`. Which kinds,
which inventories and which technology levels come through untouched, because three facts of the
setting ride on them and `units.md` argues each at length: the ship's engines are crated so it cannot
leave, the colony is a city built for an armada that never arrived, and only the hull is a component.

**The rounding is to nearest, and truncating is a bug rather than a preference.** A bare `intdiv()`
takes 15% off a baseline of 2 and returns 1 — a 50% cut out of a 15% band, because the whole
shortfall lands on the one unit there is. That halved the ship's crated engines the first time it was
written. `KitGeneratorTest` pins engines at 2 across a spread of seeds for that reason.

The draw schedule is load-bearing in the way `HomeTemplateGenerator`'s is: one roll per holding, kinds
in `entityTypes()` order, holdings in the baseline's own order. A seed's quantities are pinned as
**literals**, because comparing two runs of the same code agrees with itself perfectly while every
stored kit quietly changes.

## A table for the library, a column on the run, and no key between them

`kit_templates` is a table because a saved kit belongs to a **person**: it outlives every game it is
used at, and nothing about a run's lifecycle should reach it. `generation_runs.kit` is a column
because a kit is an **input**, exactly as `template` is — see [generation.md](generation.md).

**Using a kit copies its document onto the run.** There is deliberately no foreign key in either
direction, and that is what makes `destroy()` need no guard about games in progress: a run has to
stay a record of what it was actually given, and a gamemaster tidying their library months later must
not be able to reach into a game that has been played since. A test asserts the run is untouched by
the delete.

## Private, and the gate is a second one

`EnsureUserRunsAGame` (alias `runs-a-game`) asks whether an account holds an active gamemaster seat
at **any** game. It exists because `EnsureUserIsGamemaster` reads `{game}` out of the URL and
`abort_unless($game instanceof Game)` — so it fails closed on a route that has no game in it by
design, refusing everybody. Both gates read a seat and never `users.role`, and a source assertion
pins that for each.

**That gate is on the area; ownership is per row.** `KitTemplateController` compares `user_id`
against the signed-in account on every action naming a kit, and `index()` never leaves that account's
shelf. Ownership is a **route-bound model's state**, so it is a 403 — while `kit_template_id` at the
units stage is a **posted value**, so it is a validation message, and the `exists` rule is scoped to
the owner to get the refusal and its sentence in one rule. That is the same line the whole area
draws, cutting through the middle of one feature.

The name is unique **per owner**, so two gamemasters may each keep a "Lean start".

## The routes are their own group, and the sweeps had to be split

`GameManagementTest` held three sweeps over the `gamemaster.` prefix: an exact ten-route list, "no
route accepts `DELETE`", and "every route carries the `gamemaster` middleware". **All three are
statements about managing one game**, and none is true of this library — so each is now scoped to
`gamemaster.games.`, with a matching pair for `gamemaster.kit-templates.`.

`destroy` is therefore a plain `DELETE`, unlike anything under `gamemaster/games`. Seats are retired
because engine history keeps referring to them and starting generation over is a `POST` because it
destroys a world somebody is standing in; a kit is a document its author wrote, nothing points at it,
and deleting one is the ordinary thing to do with a draft.

There is no `edit` route: `show` renders the editor, the way the gamemaster's game screen is both the
review and the controls. `create` is declared ahead of `{kitTemplate}` so the literal wins the match.

## The way in is the sidebar, and it asks the gate's own question

This area shipped **unreachable**. The routes, the four screens, the gate and the tests were all
there and correct, and nothing in `resources/js` linked to `gamemaster.kit-templates.index` — the
only way to open the library was to type the URL. The units stage even rendered "— you have none
yet" beside the picker, which is the application telling somebody about a screen it offers no way to
reach.

Worth naming as a class rather than as one slip: **a gated area needs its entry point built with
it.** The gate, the screens and the tests all pass without one, so nothing in the verification gate
goes red — the feature is complete by every check and invisible to the person it was built for.

`AppSidebar` shows a **Kit templates** item to accounts that run a game, hidden from everyone else
the way the Administration item is hidden from members: the middleware is the boundary, and this is
only about not offering a link that would 403.

**It is hidden on the gate's own question, through the gate's own scope.** Running a game is a fact
about *seats* and never about `users.role` (see [roles.md](roles.md)), so unlike the administration
item it cannot be read off `auth.user` — `HandleInertiaRequests::runsAGame()` answers it on the
server and shares it as `auth.runsAGame`, and both it and `EnsureUserRunsAGame` go through
`GameSeat::activeGamemaster()`. That scope exists for exactly this reason. Two copies of "an active
gamemaster seat" that drift give you one of two bugs, and the second is the one that hurts: a link
that 403s, or a screen a gamemaster cannot find. `AppShellTest` pins the prop across a member, a
player, a **retired** gamemaster and an **administrator holding no seat** — that last case is the one
a prop computed off the application role would get wrong.

The prop costs one `exists()` per Inertia response for a signed-in account, and none for a guest.
`Inertia::optional()` looks like the right tool for it and is not: the sidebar renders on every page,
so a prop that only arrives on a partial reload is absent precisely where it is read.

## `download()` is the application's first file response

There is no `Storage` anywhere in this codebase and this needs none: the body is composed on the way
out by `Kit::toArray()` and returned as `response()->json(...)` with an attachment disposition. The
filename is `Str::slug()`ed because it lands in a response header and a kit's name is whatever
somebody typed.

**What comes out is exactly what `store()` accepts, seed included.** That is the whole reason the
seed lives inside the document rather than only on the row — a gamemaster downloads a kit, edits it
in a text editor and uploads it back without the round trip losing what it was drawn from. A test
walks the whole loop rather than asserting the halves.

On the screen it must be a **plain anchor**, never an Inertia `Link`: a `Link` issues an XHR visit
and an attachment response would go nowhere. `href` on a `Button` is inert here (see
[frontend.md](frontend.md)), so it is `asChild` plus a snippet.

## `Kit` refuses; the form request only checks shape

`KitValidationRules` checks that a posted payload is the right shape — arrays where arrays belong, a
known kind, a whole quantity. Everything about whether it is a **usable kit** is `Kit`'s: legal
inventories, technology levels, duplicate holdings, and every kind a game opens with being described.

**Do not restate any of those in the rules.** Those refusals have to exist in `Kit` regardless,
because an uploaded document never passes through a form request at all — a second copy would be one
that can disagree, and the one that would win is whichever the upload path happens to miss. That is
why `update()` runs the edited arrays back through `Kit::fromDocument()` instead of writing them to
the column: **an edit is held to every rule an upload is**, and a test pins it.

`Kit::fromJson()` decodes and delegates to `fromDocument()`; `fromArray()` is the trusting reader for
what this application itself wrote. Refusals from `UnitHolding`'s and `KitEntity`'s constructors
arrive as `InvalidArgumentException` — they are also how the catalogue's own kits are written — and
`Kit` catches and rethrows them as `GenerationFailed` with the entity prefixed on. A test asserts the
sentence **and** its field, because a message without the right field is one nobody sees in the right
place.

**A missing entity kind is refused, not read as "starts empty".** A kit is the whole opening
position; launching everybody with no ship is not something anybody meant by leaving a key out. The
sweep is `EntityType::startingKinds()`, never `cases()` — the list moved onto the enum so `Kit` and
`StartingUnits` cannot disagree about which kinds open a game.

## The editor is the one controlled form in the application

Every other form here is uncontrolled — `value=` seeds it and the DOM owns it (see
[frontend.md](frontend.md)). `KitEditor.svelte` cannot be: rows are added and removed at runtime, so
what posts has to be a projection of an array somebody is mutating, and a half-DOM-half-state form
posts whichever half the last interaction did not touch.

Three things about it are load-bearing:

- the `{#each}` is keyed on a **monotonic counter**, never the array index. A repeated key throws
  `each_key_duplicate` and the subtree silently stops rendering — a screen that looks stuck with one
  line in the console — and an index repeats the moment two rows swap.
- `name` is built from the **loop index** (`entities[0][holdings][2][quantity]`), so removals leave
  Laravel a contiguous list.
- the initial state is `untrack(() => ...)` off the prop. A writable `$derived` — the pattern the
  status and separation pickers use — is wrong here: it would rebuild the array on any partial reload
  and throw away unsaved rows, and its plain objects are not deeply reactive, so `bind:value` would
  update nothing.

`changeType()` snaps the level and the inventory when a kind changes, so a row is never left in a
state the server would reject. The catalogue reaches the client as a **prop** from
`PresentsKits::presentUnitCatalogue()` — which inventories a kind may sit in and whether it carries a
technology level are rules on `UnitType`, and a copy in TypeScript would show up as a holding the
editor happily builds and the server then refuses.
