# The gamemaster's own screen for a game

Globs: `app/Http/Middleware/EnsureUserIsGamemaster.php`,
`app/Http/Controllers/Gamemaster/**`, `app/Http/Requests/Gamemaster/**`,
`resources/js/pages/gamemaster/**`, `resources/js/components/GameSeatRoleForm.svelte`,
`resources/js/components/GameSeedForm.svelte`, `tests/Feature/Gamemaster/**`

`/gamemaster/games/{game}` is where somebody who runs a game manages it: its status and its roster.
It is the member-facing counterpart of `/admin/games/{game}` and carries the same roster tools minus
three things a gamemaster may not do. Read [games.md](games.md) and [roles.md](roles.md) first —
every seat rule they state still holds here and is not repeated.

## It is **not** an `/admin` route, and the gate reads a seat and nothing else

`App\Http\Middleware\EnsureUserIsGamemaster` (alias `gamemaster`) requires an **active**
`GameRole::Gamemaster` seat at the game in the URL. It must never read `users.role`, `isAdmin()` or
`App\Enums\UserRole`, exactly as `EnsureUserIsAdmin` must never read a game or a seat: an
authorisation check reads exactly one of the two role systems. Three refusals follow, and the third
is the one a later change will read as a bug:

- a **player at the same game** is refused — the seat exists, only the role is wrong;
- a **retired** gamemaster is refused, because the row outlives the person's time in the game;
- an **administrator holding no seat is refused.** Not a gap. Being an administrator says nothing
  about game membership, and `/admin/games/{game}` is the screen that answers to `UserRole::Admin`.
  A check that consulted both is the merge [roles.md](roles.md) forbids.

There is no impersonation check, unlike the admin gate. An administrator inside somebody's session
is meant to see what that account sees, and what this opens is bounded by the seats that account
actually holds.

The routes are named `gamemaster.*`, so the sweeps in `AdminAccessTest` and `GameRoleSeparationTest`
(which filter on `admin.` and `admin.games`) do not see them; `tests/Feature/Gamemaster/` runs the
mirror sweeps — every route behind `['auth', 'verified', 'gamemaster']`, and the middleware source
read with comments stripped to assert it names no application role.

## The four things a gamemaster may not do, and where each is enforced

Each is enforced in exactly one place on the server. The screen also hides the corresponding control,
via `can_retire` / `can_change_role` / `is_self` in `Gamemaster\GameController::presentSeat()`, but
**those flags are presentation** — do not turn a hidden control into the check.

1. **Rename the game.** `Gamemaster\GameStatusUpdateRequest` validates `status` and nothing else, and
   `update()` fills from `validated()`, so a posted `name` or `short_name` is *ignored* rather than
   rejected. Adding either field to those rules re-opens it in one line, which is why the test posts a
   name **and** a valid status and asserts the status moved while the names did not — a test that only
   posted a name would pass on a wholly rejected request. A short name leaves the application in turn
   reports and generated file names, so renaming one relabels artefacts that already exist.
2. **Retire yourself.** `Gamemaster\GameSeatController::retire()` compares `$seat->user_id` against
   the signed-in account. Note the deliberate consequence: a gamemaster **may still retire a peer
   gamemaster.** Retiring is reversible and leaves the row and its role intact; demoting rewrites what
   the seat was.
3. **Demote a gamemaster to a player.** `updateRole()` refuses
   `$seat->role === GameRole::Gamemaster && $role !== GameRole::Gamemaster`. **The negative
   comparison is the rule**: written as `=== GameRole::Player` it passes every behavioural test and
   stops covering everything the day a third game role exists. A source assertion pins the shape,
   because behaviour cannot see it. Setting a gamemaster seat to gamemaster is a no-op and is allowed
   through — refusing a change that changes nothing would report a boundary to somebody who has not
   crossed it, and would make a resubmit error.
4. **Change the role on a retired seat.** `updateRole()` opens with
   `abort_unless($seat->is_active, 403)`. **The administrator's copy of the action deliberately allows
   this** ([games.md](games.md): the role is what the seat *was*, and correcting it should not require
   putting somebody back into a game they left) — the divergence is the decision, not an oversight.
   Correcting the historical record is the administrator's; from inside the game the seat is already
   off the roster, so no live decision turns on its role and rewriting it changes only what the record
   says somebody was. The case that matters is a retired **player's** seat, because the demotion rule
   above does not cover it — drop the `is_active` check and that promotion goes straight through. The
   way forward is to reactivate the seat first, which the screen says and a test walks.

All four are **403s, not validation errors**: the value posted is well formed, it is the requester
who may not post it. Do not move them into a form request's `rules()`.

**The game's seed is not a fifth.** A gamemaster may see it and set it on exactly the terms an
administrator can — while the game is still in `GameStatus::Setup`, because a game in setup has not
been played and there is no run for a new seed to rewrite. That limit is about the game's state
rather than about who is asking, which is why it is a rejected field rather than a 403 and why
`Gamemaster\GameSeedUpdateRequest` differs from the administrator's copy by namespace alone, sharing
one rule through `App\Concerns\GameValidationRules`. See [games.md](games.md) for the whole of it.
Do not reimplement it here as a refusal: the day it becomes one, an administrator and a gamemaster
stop agreeing about a number that has to mean the same thing to both.

`can_change_role` is deliberately **one** flag covering rules 3 and 4, because it answers one
question — whether to render the picker — and two flags to be read together are two things to keep in
step. The "why not" text beside a fixed role is keyed off the seat's **role**, not off `is_active`: a
retired *gamemaster's* seat is refused on both counts, so telling somebody to reactivate it would send
them round a loop ending in the same 403.

## Seat validation lives in `GameValidationRules`, shared with the admin area

`gameSeatUserRules()`, `gameSeatRoleRules()` and `gameSeatMessages()` are in
`App\Concerns\GameValidationRules` because both areas seat accounts against the same contract, and
"the uniqueness check counts retired seats" is exactly the rule that must not exist in two copies
drifting apart ([games.md](games.md) explains why it carries no `is_active` condition). The two
`GameSeatStoreRequest` classes differ only by namespace.

There is deliberately **no seat destroy route here either**, and no create, delete or index for
games: a gamemaster runs a game they were given. A sweep asserts the area holds exactly ten routes —
show, update, seed update, the three generation routes and the four seat routes — and that none
accepts `DELETE`. That last part is why starting a generation over is a `POST` despite destroying
everything it destroys; see [generation.md](generation.md).

**Building the game's world is the gamemaster's**, and it lives only here: `/gamemaster/games/{game}/generation/*`
has no `/admin` counterpart, and the administrator's screen shows the same summary read-only. Running
the generators is part of running a game, and the seeds behind an accepted world are what an
administrator needs in order to explain one — not a second set of controls.

## Smaller decisions worth not re-litigating

- **`GameSeatRoleForm.svelte` takes its endpoint as an `action` prop**, typed
  `RouteFormDefinition<'post'>` from `@/wayfinder`, so the one picker serves both rosters without
  importing either area's controller. `roles` is a prop for the same reason — a caller that may not
  hand out every role passes the subset it may. `GameSeatRoleTarget` in `types/games.ts` is the
  structural slice both row shapes satisfy.
- **A gamemaster seat renders its role as a label, not a picker.** There is nothing to choose
  between when the only other option is refused.
- **The dashboard is the entry point.** `DashboardGameSection.svelte` takes a `manageable` prop,
  passed only for the gamemaster section, which renders the "Manage" link. That is presentation
  driven by which section is being rendered, not role branching in `DashboardController` — see the
  standing rule against adding any to it in [games.md](games.md).
- **The page breadcrumbs start at the dashboard**, not at a games index, because there is no
  gamemaster games list — the dashboard already is one.
- **`gamemasterOf()` and `executableSourceOf()` live in `tests/Pest.php`**, not in a test file. A
  helper declared in a test file is only loaded when that file is, which a `--filter` run need not
  do, and both sides of the role boundary now assert with them.
