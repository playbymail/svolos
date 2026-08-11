# Games and seats

Globs: `app/Enums/GameStatus.php`, `app/Enums/GameRole.php`, `app/Models/Game.php`,
`app/Models/GameSeat.php`, `app/Http/Controllers/Admin/GameController.php`,
`app/Http/Controllers/Admin/GameSeatController.php`, `app/Http/Requests/Admin/Game*.php`,
`app/Concerns/GameValidationRules.php`, `database/factories/GameFactory.php`,
`database/factories/GameSeatFactory.php`, `resources/js/pages/admin/games/**`,
`resources/js/components/GameSeatRoleForm.svelte`, `resources/js/types/games.ts`,
`tests/Feature/Admin/Game*.php`, `tests/Feature/GameModelTest.php`,
`app/Http/Controllers/DashboardController.php`, `resources/js/pages/Dashboard.svelte`,
`resources/js/components/DashboardGameSection.svelte`, `resources/js/types/dashboard.ts`,
`tests/Feature/DashboardTest.php`

A **game** is one run of the thing this application exists to run. A **seat** joins one account to one
game and carries the game role it holds *there*. Administration lives at `/admin/games`.

## `GameRole` carries no application permissions — see [roles.md](roles.md) first

This is the rule most likely to be undone, because `UserRole` and `GameRole` look similar enough to
invite a merge. They are two unrelated systems and [roles.md](roles.md) is the full statement of why.
The short version: only `UserRole::Admin` opens `/admin`, a `GameRole::Gamemaster` seat opens nothing,
and an administrator holds no seat at any game merely by being an administrator.

Every route in the games area is an ordinary `/admin` route in the `['auth', 'verified', 'admin']`
group. Nothing in `GameController` or `GameSeatController` consults a seat to decide access, and
nothing in them reads `users.role`. `tests/Feature/Admin/GameRoleSeparationTest.php` is what holds
this: it sweeps the route collection so a route added later is covered without anybody adding a case,
requests every one of them as a **member holding a gamemaster seat at the very game addressed**, and
also reads `EnsureUserIsAdmin` and `GameSeatController` with comments stripped to assert neither
mentions the other system. Comments are stripped on purpose — the prose has to be free to name both
systems in order to say they are unrelated.

## Seats are retired, never deleted — there is no seat destroy endpoint

`is_active = false` is how somebody leaves a game. There is deliberately **no**
`DELETE /admin/games/{game}/seats/{seat}`, and the screen looking incomplete without a delete button
is not a reason to add one: engine history keeps referring to seats — a turn report names the seat that
submitted it — so deleting the row turns recorded history into a dangling reference. `retire()` is the
delete button and `reactivate()` is why keeping the row is worth it.

`GameSeatAdminTest` asserts this as a **sweep** of `admin.games.seats.*` rather than a single
assertion, so a destroy route fails whatever it is named, and separately walks every route whose URI
contains `seats` asserting none accepts `DELETE`.

The one thing that does destroy seats is deleting the whole game: `game_seats.game_id` cascades. That
is why the delete confirmation on the list names how many seats go with it, counting retired ones,
because those rows disappear too.

## The uniqueness check counts retired seats, and the assignable list agrees with it

Because a departed account still owns its row, it can **never** get a second one — coming back is a
*reactivation*. Three things have to stay in step, and all three are tested:

- the unique index is on `(game_id, user_id)` with **no** `is_active` in the key. Adding it would let a
  retired account get a second row and break the reactivation contract silently;
- `GameSeatStoreRequest` uses `Rule::unique(GameSeat::class, 'user_id')->where('game_id', …)` with
  **no** `->where('is_active', true)`. Adding that condition does not merely loosen the rule: validation
  then passes, the unique index throws, and the administrator gets a raw
  `UniqueConstraintViolationException` instead of a message. The message is exactly
  **"That account already has a seat in this game."**;
- `GameController::assignableAccounts()` excludes every account holding a seat, **active or retired**,
  so a retired holder is never offered in the first place. The exclusion is a subquery on `game_seats`,
  not a relation on `User` — the user-side relation belongs to the member-facing screens.

The scoping `game_id` is what keeps the rule per-game: an account may hold a seat at many different
games, and a test asserts that too.

## Short names are uppercased **before** the character check

`short_name` goes into turn reports and generated file names, so it is capped at
`Game::SHORT_NAME_MAX_LENGTH` (16, matching the column) and restricted to `[A-Z0-9-]`.

**The order is the whole rule.** `prepareForValidation()` on `GameStoreRequest` and
`GameUpdateRequest` folds the value with `normalizedShortName()`, and only then does the anchored
`regex:/^[A-Z0-9-]+$/` see it. Both halves are load-bearing:

- `run-1` is accepted and stored as `RUN-1`, which only works because the folding came first;
- `run 1` is **rejected**, which only works because the class excludes the space and is checked after
  the folding.

Fold after the check and the first breaks; put `a-z` in the class and the folding becomes invisible and
lowercase gets stored. Each of those three mutations fails a test. Rejection message is exactly
**"The short name may only contain letters, numbers and hyphens."** — it names what is allowed rather
than reporting a pattern, and says nothing about case, because case is not a mistake the administrator
can make. Uniqueness applies to the folded value, so `taken` collides with a stored `TAKEN`.

The constant lives on `Game`, not in `GameValidationRules`: a trait constant cannot be read through the
trait's own name (`GameValidationRules::SHORT_NAME_MAX_LENGTH` is a fatal error), and the rules and the
tests need one place to agree on.

## Count with the `activeSeats()` relation, never a `withCount` closure alias

`withCount(['seats', 'activeSeats'])` — and `loadCount(['seats', 'activeSeats'])` on the show screen —
name a real relation on `Game`, which is what backs the declared
`@property-read int|null $active_seats_count`. The equivalent
`'seats as active_seats_count' => fn ($query) => $query->where('is_active', true)` gives identical
output while leaving that property backed by nothing but a string in an array.

**Larastan will not catch the alias.** This was checked: `phpstan.neon` does not enable
`checkModelProperties`, so an undeclared model property reads as `mixed` and passes level 8 either way —
`$game->totally_bogus_property` analyses clean. (The declared property *is* honoured: reading
`active_seats_count` where a string is wanted errors with `int|null given`.) So the shape is held by a
source assertion in `tests/Feature/GameModelTest.php`, not by the analyser. Do not enable
`checkModelProperties` to fix this; do not drop the test.

## Seat routes are nested inside `Route::scopeBindings()`

`{seat}` then resolves through `Game::seats()` — Laravel derives the relation name as
`Str::plural(Str::camel('seat'))` — so a seat belonging to another game **404s** instead of being edited
through the wrong game's URL. Removing the call does not fail loudly: both parameters bind
independently and game B's URL happily writes game A's seat with a 302. Tested behaviourally on all
three seat routes (re-reading the seat afterwards, so the assertion is that the write never happened),
plus one test naming the cause via `Route::enforcesScopedBindings()`.

Two consequences for the code: `Game $game` must stay **first** in every seat controller signature,
because the scoped binding resolves the child against the parameter before it; and `Game::seats()` must
stay **unfiltered**, or a retired seat would stop being reachable through its game's URL and could never
be reactivated. `activeSeats()` is the filtered one.

## The member dashboard: `User::gameSeats()`, and two states that are not one state

`DashboardController` is the member-facing counterpart to `/admin/games`: the games the signed-in
account holds an **active** seat in, split into a gamemaster section and a player section, each
ordered by short name. It reads seats through `User::gameSeats()` — named that rather than `seats`,
which on an account reads as furniture — and that relation is **unfiltered**, like `Game::seats()`,
because a retired seat is still the row occupying the account's place in the unique index. The
dashboard is what filters on `is_active`; the relation is not.

The screen is deliberately role-blind in the application sense: an administrator gets exactly what a
member gets, because holding `UserRole::Admin` grants no seat anywhere, and because this is where
`ImpersonationController::store()` lands a session that has just become somebody else. Do not add
role branching to it.

Two decisions are the ones a later change will want to undo:

- **Archived games ship in the payload, flagged `is_archived`, rather than behind a query
  parameter, a partial reload or a deferred prop.** The toggle is per-section `$state` in
  `DashboardGameSection.svelte`, so revealing an archived game is a filter over rows already on the
  client and costs no round trip. They are also **interleaved** in the one short-name ordering
  rather than segregated to the end, so the toggle reveals rows in place. This is the opposite
  choice from `Game::unarchived()`, and deliberately: the scope answers "which games are still in
  play", while this screen answers "which games am I in", and a game you are still seated at does
  not stop being yours because it was put away.
- **"Section absent" and "section present but entirely archived" are different states.** A section
  with no seats is **missing from the props entirely** (`missing('playerGames')`), so the page has
  nothing to decide: a key that is there is a heading to render. A section whose every game is
  archived is present — the account really is in those games — keeps its heading and its toggle, and
  says so in words instead of rendering an empty list. Collapsing the two, by defaulting either prop
  to `[]` or by filtering archived games out on the server, turns "you are in two archived games"
  into "you are in no games". `tests/Feature/DashboardTest.php` asserts `missing()` for the first
  and `has()` for the second, next to each other, for exactly that reason.

One query for the seats and one for their games (`with('game')`), whatever the roster size; the
short-name ordering is done on the loaded collection rather than in SQL, because ordering a
`game_seats` query by `games.short_name` would need a join purely to sort a handful of rows. No seat
counts are presented here — who else sits at a game is the administrator's screen.

## Smaller decisions worth not re-litigating

- **`GameStatus` is stored, not derived** — unlike `InvitationStatus`, which comes from timestamps. A
  paused game and an active one differ by a decision somebody made. `Archived` is the only case with
  behaviour: `Game::unarchived()` excludes it. The games list deliberately shows archived games anyway
  (it is the administrator's inventory, and a game put away has to be findable to bring back);
  `unarchivedCount` is what the heading uses to say how much of the list is live.
- **A new game always starts in `setup`.** `GameStoreRequest` accepts no `status`, so a posted one is
  ignored rather than honoured. Creation redirects to the new game's own screen, not the list, because a
  game with an empty roster is not finished.
- **`is_active` is out of `GameSeat`'s `#[Fillable]`** so it can only change through the retire and
  reactivate endpoints, never as a side effect of a role change. Pinned at the model, because an
  endpoint test still passes when it becomes fillable.
- **A retired seat's role can still be changed**, and reactivating keeps whatever role the seat had.
  The role is what the seat *was* in the game's history; correcting it should not require putting
  somebody back into a game they left, and reactivating somebody is not a decision about their role.
- **Factory states** are `active()`/`paused()`/`completed()`/`archived()` on `GameFactory` and
  `gamemaster()`/`retired()` on `GameSeatFactory`. There is no `setup()` or `player()` state — those are
  the factory *and* column defaults, so a state saying so would be a second place to keep in step.
  `GameSeatFactory` seats a plain **member** by default, deliberately: a factory that quietly made every
  seat-holder an administrator would make the role-separation tests pass for the wrong reason.
- **`GameFactory` draws one unique suffix and feeds both unique columns**, so a single guarantee covers
  `name` and `short_name`; the short name it builds satisfies the real validation rule.

## Frontend

Pages are `admin/games/Index.svelte` and `admin/games/Show.svelte`, resolving to `AppLayout` through
`app.ts` like the rest of `admin/**` (see [frontend.md](frontend.md)). `Show.svelte` exports `layout` as
a **function** rather than an object because its last breadcrumb is the game's name, which only the
server knows.

`GameSeatRoleForm.svelte` is a per-row component for the same reason
`UserRoleForm.svelte` is (see [sessions.md](sessions.md)): each picker holds the in-progress choice in a
writable `$derived` off that row's `seat.role`, so a refused change snaps back. A map of choices keyed by
seat would have to be `$state` re-seeded from an `$effect`, which `eslint-plugin-svelte`'s
`prefer-writable-derived` rejects.

One `svelte-check` trap: the two `<script>` blocks share **one** module scope, so a type imported in
`<script module>` must not be imported again in `<script>` — it is a duplicate-identifier error.
`Show.svelte` imports `AdminGame` once, in the module block, and says so in a comment.
