# The player's own screen for a game

Globs: `app/Http/Middleware/EnsureUserIsPlayer.php`, `app/Http/Controllers/Player/**`,
`app/Http/Requests/Player/**`, `app/Actions/Games/**`,
`app/Notifications/GameActivatedNotification.php`, `resources/js/pages/player/**`,
`resources/js/types/player.ts`, `resources/js/lib/game-status.ts`, `tests/Feature/Player/**`,
`tests/Feature/GameSeatNumberTest.php`

`/games/{game}` is where somebody playing a game looks at it: the status and turn, the empire they
are, and — once the game is active — where in the cluster they begin and what is in that system. It
is the counterpart of `/gamemaster/games/{game}`, shaped by the opposite question: that screen asks
what this world is and who is in it, this one asks what my empire is and what I can see from it.

Read [games.md](games.md), [gamemaster.md](gamemaster.md) and [roles.md](roles.md) first — every seat
rule they state still holds here and is not repeated.

## The gate reads a **player** seat, and refuses a gamemaster on purpose

`App\Http\Middleware\EnsureUserIsPlayer` (alias `player`) requires an **active** `GameRole::Player`
seat at the game in the URL, and reads nothing else. It is the sibling of `EnsureUserIsGamemaster`
and must be kept one: never `users.role`, never `isAdmin()`, never `App\Enums\UserRole`. Four
refusals follow and three of them read as bugs to somebody looking at the middleware alone:

- a **member with no seat**, and a **player at another game**;
- a **retired** player, because the row outlives the person's time in the game;
- an **administrator holding no seat.** Not a gap — being an administrator says nothing about game
  membership, and a check consulting both role systems is the merge [roles.md](roles.md) forbids;
- a **gamemaster at the very same game.** This is the one worth writing down. The screen is about an
  empire, and a gamemaster has none: `GenerateHomeStellia` places players and not gamemasters,
  `GenerateAssets` gives players a colony and a ship and gamemasters nothing, and a seat that is not
  playing has no empire to number. Letting them through would mean a screen made almost entirely of
  empty states, answering a question `/gamemaster/games/{game}` already answers better. A gamemaster
  who wants a player's view is really asking for a *particular* player's view — a different feature.

Like the gamemaster gate and unlike the admin one, there is no impersonation check.

## A player is not shown the cluster

`PresentsGeneration::presentLocations()` is **omniscient** — all hundred systems, every home, every
player's account name — because it is the review surface for a world being generated.
`Player\GameController` must never call it. It shapes the seat's own home instead, one location, in
the same array shape so `ClusterHexMap` needs no player-specific branch.

The rest of the cluster is not withheld for ever; it is withheld **until it has been explored**, and
nothing in the schema records what a player has seen yet. When something does, it belongs in
`presentHomeLocation()`'s place — not in a filter bolted onto the omniscient version. The two
questions have different answers and must not share a method.

Two fields on that row differ in meaning from the gamemaster's copy even though the shape is
identical, and both are asserted:

- **neither count is ever null.** On the gamemaster's screen null means "that stage has not run yet",
  a state this screen cannot be in — a game only becomes active once every stage is accepted;
- **`home_player_name` is the empire name**, from `GameSeat::empireName()`, not the account name.
  Inside a game a player is their empire; the account behind it is the administrator's business.

`presentLocationDetail()` *is* reused unchanged, and is the only thing this controller takes from
that trait. A player asks it about their own home, where every entity is their own. **The day
anything in this game moves, the `player_name` it puts on each entity needs gating here.**

The map and the probe report are withheld while the game is not `Active`; the profile is not, because
the sensible moment to name an empire is before the game starts.

## The empire number is a column, not a position

`game_seats.number` is assigned once by `GameSeat::booted()` — on the model rather than in either
seat controller, so a factory and a seeder get one too — and it is the **third** exclusion from that
model's `#[Fillable]`, with the strictest reason of the three: nobody ever posts an empire number.

The obvious alternative is the seat's position among the game's active player seats, and it is wrong
in a way that only shows up later. Retiring empire 2 would renumber everybody after them, and the
engine history that already named them would start pointing at somebody else. So the sequence
**counts retired seats and never reuses a number**, per game, held by `unique(game_id, number)`.
Gamemaster seats are numbered too, because a seat's role can change.

The index rather than a lock is what refuses a collision between two seats created at the same
instant. Seats are added one at a time by somebody looking at a roster; serialising every creation
would be paying for a race nobody has.

## The empire name default is a fallback, never a stored value

`empire_name` is nullable and null is the point. `GameSeat::defaultEmpireName()` builds "Game ACME
Seat 3" at read time; `empireName()` returns the chosen name or that. Writing the default into the
column at creation would be a copy that goes stale the moment an administrator renamed the game, and
it would throw away the only record that this player has not chosen yet.

They are **two methods, not one**, because the payload sends both `empire_name` and
`empire_name_default`: the form prefills from the default while the page can still tell an unnamed
empire from one deliberately called "Game ACME Seat 3". Anything merely *showing* an empire uses
`empireName()`.

Empire names are deliberately **not unique** within a game. Empires are told apart by their numbers,
which are; refusing a duplicate name would let an early player take one by accident.

## `email_notifications` is `required`, not `sometimes`

The rest of the application validates checkboxes as `['sometimes', 'boolean']`, because the `Checkbox`
component renders its hidden input only while ticked and an absent field means false. That is right
for a flag that is *supplied*, and wrong for one that is *stored*: an absent field cannot turn
anything off, so `sometimes` would make the box impossible to untick.

So the screen gives the `Checkbox` **no `name`** and posts its own always-present hidden input with
`1` or `0` beside it. Two fields with the same name would collide; do not add `name` back.

## Activation is announced from an action, guarded by `wasChanged()`

`App\Actions\Games\AnnounceGameActivation` runs after the save in **both** status endpoints — the
gamemaster's and the administrator's — and holds the whole rule, so one line at each call site. A
game activated from `/admin` has started just as much, and a player who opted in is owed the mail
whoever pressed the button.

The guard is `wasChanged('status') && status === Active`, read *after* the save. `status === Active`
alone would mail everybody again every time the form was saved with the status it was already
showing, which is the ordinary result of pressing the button twice. `wasChanged()` also gets the case
that matters most right: a game returning from `Paused` genuinely has restarted, and is announced.

**Not a model event.** An `updated` hook on `Game` would fire inside seeders, factories and tests
that have no interest in mail, and a notification sent as an invisible consequence of `save()` is the
kind of thing discovered in a production mailbox.

Recipients are active player seats with the opt-in, and nobody else — not the gamemaster who pressed
the button, not somebody who has left. Agents need **no exclusion of their own**, and adding one
would be a rule nobody asked for: an agent cannot sign in, so its seat's opt-in can never be turned
on. See [agents.md](agents.md).

`GameActivatedNotification` is not queued, for the reason `InvitationNotification` is not — see
[invitations.md](invitations.md). A fan-out large enough to be worth queueing is a *different*
notification with a different failure mode, and should be given `ShouldQueue` and a worker rather
than this one being changed underneath a gamemaster who has no way to tell.

## `games.turn` has no writer, on purpose

The column exists so this screen can report where the game is instead of leaving a blank on it.
Nothing in the application advances it: turn processing and order resolution live in the game engine,
and the engine is not wired up here. Every game reads 0.

`Game::yearAndQuarter()` is the only thing that interprets it, and it is **derived** — a year column
beside the turn column is a second copy of one fact. A turn is a quarter and four quarters make a
year: turn 1 is year 0 quarter 1, turn 4 closes year 0, turn 5 opens year 1.

**Turn 0 needs no special case, and that is load-bearing.** Both expressions hold for it only because
PHP truncates `intdiv()` toward zero and gives `%` the sign of its dividend, so
`intdiv(-1, 4) === 0` and `(-1 % 4) + 1 === 0` — year 0, quarter 0. In a language that floored
instead it would read as year -1. `tests/Feature/GameModelTest.php` pins that case for exactly this
reason; do not "simplify" either line without running it.

Adding a writer means deciding what advancing means for a paused game and whether it can ever go
backwards. Neither question has an answer worth guessing at yet.

## The dashboard is how anybody gets here

`DashboardGameSection` takes `manageable` and `playable` — two props rather than one enum, because
they are two destinations gated by two different seats, and they are never both true. Losing either
leaves that half of the roster with no way off the dashboard and the feature behind it unreachable,
which is why `DashboardTest` asserts them against the **source**: there is no jsdom here, so no test
renders the markup and the payload is identical either way.

Player rows link in **every** status, archived and setup included. There is something to do on that
screen whatever the badge beside it says.
