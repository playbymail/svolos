# The opening position: entities and what they hold

Globs: `app/Enums/Asset*.php`, `app/Enums/EntityType.php`, `app/Generation/AssetHolding.php`,
`app/Generation/StartingAssets.php`, `app/Actions/Generation/GenerateAssets.php`,
`app/Models/Entity.php`, `app/Models/Asset.php`,
`database/migrations/*_create_entities_table.php`, `database/migrations/*_create_assets_table.php`,
`database/factories/{Entity,Asset}Factory.php`,
`resources/js/components/LocationSystemPanel.svelte`, `tests/Unit/AssetTypeTest.php`,
`tests/Unit/StartingAssetsTest.php`, `tests/Feature/Gamemaster/AssetsTest.php`

Read [generation.md](generation.md) first — this is the **sixth** stage of the machine described
there, and every rule about *when* a stage may run applies here unchanged. Read
[agents.md](agents.md) too: it settled what an entity is before one existed.

An **entity** is a colony or a ship: the only kind of thing that accepts orders. An **asset** is a
quantity of one kind of thing an entity holds, in one of three assignments. The stage puts a colony on
every player's home world and the ship that brought them into orbit above it, each with the assets it
begins with.

## Control is a seat, and the arc stays dead

`entities.game_seat_id` is non-nullable and is the whole of control. [agents.md](agents.md) had
already ruled out the alternative by name: the `(Player | Agent) --o< Entity` exclusive arc existed
only because Player and Agent were separate tables, and here an agent is a `User` with `is_agent` set
holding an ordinary `GameSeat`. **Do not add an owning `user_id`, a polymorphic owner, or a second
nullable key.** Any of them re-creates the arc one level down.

A seat rather than an account because control is per-game while an account is not, and because seats
are retired rather than deleted — so an entity outlives its player leaving, which
`AssetsTest` asserts directly.

## `generation_run_id` is nullable, and that null is the only one in the schema

Every other generated table hangs off its run with a non-nullable key, because a location has no
meaning apart from the run that drew it. Entities are the first thing here that is **not purely an
artefact**: these were placed by the assets stage, and a ship built during play will have been placed
by no run. The nullable column is what distinguishes the two, and it costs nothing — `discard()` is
still `$run->entities()->delete()` and starting over still takes them by cascade.

Nothing built in play can be lost that way: a game cannot leave setup until its generation is
complete, and `restart()` refuses any game that has. `EntityFactory` leaves the column **null by
default** so a test that wants a run-placed entity has to say `->for($run)` — the distinction stays
visible in the tests that turn on it.

## `assignment` is stored; `zone` is derived; the difference is who decided

A crated mine and a working mine are the same kind in two states, and moving between them is an act
somebody performs — so it is a column, the way `games.status` is. Contrast `planets.zone`, which has
no column because it is a function of the ordinal and the star's planet count and could only ever
disagree with them. Ask which one a new field is before adding it.

Which assignments a kind may sit in is a **rule**, and it lives on `AssetType::assignments()` /
`allows()`, enforced in `AssetHolding`'s constructor rather than by a check constraint. Only
`Structure` and `Engine` may be `Infrastructure`, because infrastructure means the frame and systems
of the entity itself; mines and factories are never infrastructure, because a colony's mine is a thing
it operates rather than a thing it is. `assignments()` is written case by case with **no `default`
arm** on purpose: a `default` would quietly give a new kind the commonest answer, and deciding where a
new kind may sit is the whole of adding one.

## The stage draws nothing, and three things follow from it

Every player gets the same kit, down to the last tonne, because the alternative is that the seed
decides who begins ahead. That is the same fairness argument that makes every player's home *world*
identical (see [home-template.md](home-template.md)), and it is stronger here: the home template's
neighbours may differ because what a system is worth to mine is a thing to discover, while what you
are handed on turn one is not.

So:

- **`StartingAssets` is on `generationSources()` and not on `seededGenerators()`.** The second list
  asserts that a class contains `SeededRandomizer::for`, which this one must never do. Being on the
  first is what catches somebody later reaching for `Arr::random()` to make the kits "more
  interesting" — precisely the change that would look like an improvement.
- **`GenerationRunRequest` exempts the stage from the "choose a different seed" rule**, through
  `ignoresTheSeedEntirely()`. This is the *opposite* reason the home stellia is exempt, and the two
  are worth telling apart: there the same seed gives a genuinely new arrangement, here no seed gives
  anything different at all. What regenerating is for is a roster that has changed.
- **The run still records a seed.** A run stores what it was asked for, the way it records `traveler`
  on a stage that never reads it.

## A player with nowhere to stand is skipped and counted, not a failure

Somebody seated after the homes were arranged has no home stellium and therefore no home world.
`GenerateAssets` skips them and reports `players_without_a_home` in the summary, so the review card
says it out loud rather than quietly placing fewer colonies than there are players.

It is **not** a `GenerationFailed`: there is no field on that form a gamemaster could change to fix
it, and the remedy is regenerating the homes. The hole is already covered elsewhere —
`Game::playersWithoutHomeStellium()` reports it and `gameStatusRules()` refuses to let such a game
become `Active` — so this stage's job is to be honest about it, not to invent a second gate.

## The ship's engines are in the hold on purpose

`StartingAssets::ship()` puts `Engine` under `Cargo` and nothing under `Infrastructure` but the hull.
That is `docs/player-copy.txt` written as data: "The main engines are gone. Burned out sometime during
the voyage." A ship's ability to move will be read off its **infrastructure**, so this ship cannot
leave until somebody installs them. Moving those two units to `Infrastructure` would undo the premise
the whole game opens on without touching a line of rules code, which is why
`StartingAssetsTest` and `AssetsTest` both assert it.

The numbers in the manifests are content and are meant to be tuned. The *shape* — which assignment
each kind sits in — is not.

## Where it is reviewed, and why it has no screen of its own

The stage added **no routes**. What was placed rides on `locationDetail`, the optional prop the
planets are already reviewed through, because "what is at this system" and "what is standing in it"
are one question asked once — and because only a handful of the hundred locations have anybody at
them. Every planet carries an `entities` key, empty on the ones nobody is at: a screen that had to
tell "nobody is here" from "this payload predates the stage" would be reading a distinction the server
never meant to draw.

Assets are ordered server-side by assignment and then by kind, so infrastructure — the part that says
what the thing *is* — reads first. `LocationSystemPanel` groups the list it is handed rather than
sorting it again: a second ordering could disagree with the first, and the one that would win is the
one nobody is looking at.

**That pairing has already broken once, and both halves of the fix are load-bearing.** The sort was
written as `sortBy([$closure, $closure])`, which silently sorts nothing — Laravel calls a callable
comparison as a full comparator, so a one-parameter closure returns a position where a comparison
belongs (see [php.md](php.md)). The interleaved list then reached a `{#each}` keyed on the group, and
`each_key_duplicate` left the panel showing its loading skeleton for ever with only a console error
to say why (see [frontend.md](frontend.md)). So:

- the server sorts with **one** closure returning `[assignmentIndex, typeIndex]`;
- the panel looks an assignment up across every group it has already made, so a duplicate key is
  impossible whatever arrives;
- `AssetsTest` asserts the **whole sequence** — that each assignment appears in one contiguous run,
  in the enum's order. A test asserting only that infrastructure comes first passes on an interleaved
  list, which is exactly how this shipped.

## What is deliberately absent

- **Orders.** Only entities accept them and the check belongs in a domain action both transports
  call, never in a controller — see [agents.md](agents.md), which is still owed that half.
- **Whether a *particular* ship can move.** `EntityType::isMobile()` is a fact about the kind. Fuel
  and installed engines are a rule, and no order asks it yet.
- **Individual units.** `(entity_id, type, assignment)` is unique and the row carries a quantity, so
  no unit can differ from its neighbour — no condition, no damage, no name. The day something needs
  that it wants a second table, not the exploding of this one.
- **`mass` and `volume` in the payload.** Both are functions of the kind and the quantity; shipping
  them would be a second copy of `AssetType` that could disagree with the first.
