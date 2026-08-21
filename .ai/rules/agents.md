# Agents

Globs: `app/Actions/Agents/**`, `app/Models/AgentCredential.php`,
`app/Http/Middleware/AuthenticateAgent.php`, `app/Http/Controllers/Api/**`,
`app/Http/Controllers/Admin/Agent*.php`, `app/Http/Requests/Admin/AgentStoreRequest.php`,
`app/Http/Resources/AgentIdentityResource.php`, `app/Concerns/PresentsAgents.php`, `routes/api.php`,
`database/migrations/*_add_is_agent_to_users_table.php`,
`database/migrations/*_create_agent_credentials_table.php`,
`database/factories/AgentCredentialFactory.php`, `resources/js/pages/admin/agents/**`,
`resources/js/components/AgentTokenPanel.svelte`, `resources/js/types/agents.ts`,
`tests/Feature/Agents/**`, `tests/Feature/Admin/Agent*Test.php`, `docs/reference/agent-api.md`,
`docs/how-to/connect-an-agent-to-a-game.md`, `docs/how-to/keep-an-agent-token-safe.md`

An **agent** is an account played by software rather than by a person. It holds seats, takes a game
role, and will have its orders attributed to a seat exactly as anybody else does. The only thing that
differs is how it authenticates: a bearer token per seat, against `api/*`, instead of a password
against a form.

## An agent is a `User` with a seat, because that is what makes the arc disappear

Earlier versions of this game modelled control as `(Player | Agent) --o< Entity`: two nullable
foreign keys and a check constraint saying exactly one is set. That exclusive arc existed **only**
because Player and Agent were separate tables.

They are not here. The thing an agent needs to be is already built, and it is `GameSeat` — one
account's place at one game. Control is per-game, a `User` spans games, and seats are retired rather
than deleted precisely so engine history can keep naming them. So entity control is one non-nullable
`game_seat_id`, with no arc and no check constraint.

**Do not give agents their own model.** It does not remove the arc, it moves it: `game_seats.user_id`
is a non-nullable foreign key and `GameSeat::user()` is relied on throughout, so an `Agent` table
would need a nullable `user_id`, a nullable `agent_id` and the constraint — the same hoop, one level
down from where it used to be.

## Agent-ness is a column, not a `UserRole` case, and not "has a credential"

`users.is_agent` is a boolean, kept out of `#[Fillable]` and assigned only by
`App\Actions\Agents\CreateAgent`, the same treatment `role` gets and for the same reason.

It is **not** a third `UserRole` case. `role` decides what an account reaches in the administration
area; agent-ness decides whether a person may sign in as it at all. Two questions, two columns —
folding them together is the beginning of the general role system [roles.md](roles.md) rules out.

It is **not** derived from owning a credential either, and this is the subtle one. A credential
belongs to a *seat*. The moment an agent is delegated a person's seat — which the schema already
allows — that person's seat carries a credential, and a derived flag would start calling the person
an agent.

## The credential belongs to a seat, so its scope is structural

`agent_credentials.game_seat_id` is **unique**. One seat, at most one live token, and a token that
can only ever act in one game because a seat only exists in one game. That is why there is no
abilities list, no scope string and no `laravel/sanctum`: the thing a scope system would express is
already a foreign key.

It also leaves room for the delegate case — an automation driving a person's seat with their
knowledge — as a row rather than a migration. `AgentCredentialController` deliberately refuses it
today (`abortUnlessAgentSeat()`), because whose consent that needs is a question nobody has answered
yet. When it is answered, the other half is stamping `agent_credentials.id` on the submitted order so
a turn report can say which of the two sent it.

## Minting is revoking, and there is no expiry

The token is stored as an unsalted sha256, exactly as `invitations.token` is and for the same reason:
the column must be **searchable**, because a request arrives carrying the token and nothing else. See
[invitations.md](invitations.md), which argues it in full.

The plain text is therefore unrecoverable, which fixes two behaviours rather than suggesting them:

- **minting again rotates.** The row is upserted on the seat, so the previous hash is overwritten and
  the previous token stops working. Do not "fix" this by storing the plain text beside the hash, and
  do not add a `plain_token` column so a token can be shown twice;
- **there is no `expires_at`.** A token that expires mid-game is an outage, not a safety net.
  Revocation is minting a replacement, or `DELETE`ing the credential outright — which exists as a
  separate action because "this leaked, here is a new one" and "stop this agent now" are different
  requests.

`App\Actions\Agents\IssueAgentCredential` is the only place a token is generated, the way
`IssueInvitation` is for invitations. `AgentCredential` declares **no `#[Fillable]` at all**, so a
future `AgentCredential::create($request->validated())` throws rather than trusting request input.

## Shown once is a property of the flash bag, not of the screen

The plain text goes into `Inertia::flash('agentToken', …)` and nowhere else. Flash data rides on the
response after the redirect and is gone on the next request, so "once" needs no dismissal state, no
cleanup, and nothing to go wrong if the administrator closes the tab.
`tests/Feature/Admin/AgentCredentialTest.php` asserts both halves — that it is there, and that it is
missing on the following request. Never render it from a prop, and never log it.

## Seating is a gamemaster's; minting is an administrator's

A **person** is added to a roster through the game's own seat screens, like any other account,
because a roster is one game's business and a gamemaster runs it. Issuing a credential is on
`/admin/agents` behind `admin`, because a bearer token is an account-level secret rather than a
decision about one game.

An **agent** can also be seated from its own screen, through `AgentSeatController`. That is a
workflow concession, not a change of ownership, and it exists because the first version without it
was unusable: a token belongs to a seat, so a newly created agent had nowhere to hang one, and
finishing the job meant leaving for a game's roster and coming back. From the index the token column
simply read "None" with no way to act on it.

Three things keep it from becoming a second roster, and all three are asserted:

- it **refuses a non-agent account** with a 404, so people are still seated where the whole roster is
  visible;
- it seats as a **player** and offers no role choice — who runs a game is a decision about the game,
  and it stays with `GameSeatRoleForm` on the roster;
- its uniqueness comes from `GameValidationRules::gameSeatGameRules()`, the mirror of
  `gameSeatUserRules()` in the same trait, so the rule that counts **retired** seats is stated once.
  `AgentController::assignableGames()` agrees with it rather than restating it, and leaves out
  archived games as well since `AuthenticateAgent` refuses their tokens anyway.

The credential routes are `admin/agents/{user}/credentials/{gameSeat}`, and both parts of that path
are load-bearing:

- **`{gameSeat}`, not `{seat}`.** `Route::scopeBindings()` resolves the relation from the *parameter
  name*, so `{seat}` looks for `User::seats()`, which does not exist — this side of the relation is
  deliberately `gameSeats()`.
- **`credentials/…`, not `seats/{seat}/credential`.** `GameSeatAdminTest` sweeps every route whose
  URI contains `seats` and fails any accepting `DELETE`, because seats are retired and never deleted
  ([games.md](games.md)). A revoke route nested under `seats` trips that sweep on wording alone.
  Naming the resource for what is actually deleted keeps the stricter invariant intact.

## Four places a person could reach an agent account, all closed

Every agent has 64 random characters for a password, so in practice no sign-in would succeed anyway.
That is a lucky accident, not a control, and a test that relied on it would pass whether or not the
rules existed. `tests/Feature/Agents/AgentAccountIsolationTest.php` therefore gives an agent a
**known** password before trying, and asserts each refusal positively:

| Where | What it does |
| --- | --- |
| `FortifyServiceProvider::refuseAgentSignIn()` | `Fortify::authenticateUsing()` returns null for an agent — **after** the framework has checked the password, so an agent address and a wrong password fail identically |
| `User::sendPasswordResetNotification()` | returns early, so no reset link is ever sent |
| `ImpersonationController::store()` | a third refusal beside self and administrator |
| `Admin\UserController::updateRole()` | an agent is neither promotable nor demotable |

`Admin\UserController::index()` also filters agents out: every column on that screen describes how a
*person* reaches an account, so an agent reads as a row of alarming blanks beside controls that 403.

## `api/*` has no session, and the failures answer differently on purpose

`routes/api.php` is registered outside the `web` group: no cookies, no CSRF, no session. The bearer
token is the entire credential, and `AgentApiTest` asserts positively that a signed-in administrator
gets a 401 there — a surface that quietly acquired a session would start accepting a cookie as a
credential and `AuthenticateAgent` would never see the difference.

- **401** for a missing or unrecognised token, saying no more. A caller holding neither should not
  learn which it was, and a rotated-away token is simply unknown now.
- **403, naming the reason**, for a recognised token whose seat is retired or whose game is archived.
  That caller has already proved it holds a live credential, so there is nothing left to leak — and
  the distinction is what stops an operator rotating a token that was never the problem.

`AgentApiTest` sweeps every route named `api.*` for **both** `throttle:agent` and `agent`, the way
`AdminAccessTest` sweeps `admin.*`, and asserts the throttle comes first. A route added without them
is an unauthenticated, unbounded window into a game.

### The throttle is two limits, and it is written on the route group

`api/*` is the only surface a stranger can reach that inspects a credential, and unlike the login
form it has no session and no CSRF token slowing anything down. It shipped without a limit; a
production check found 80 bad-token requests answered 80 times with no 429.

The risk is the **worker pool, not the token** — 48 characters of base62 is not brute-forceable, so
nobody guesses their way in. What an unlimited endpoint offers is a database query per request
against a PHP-FPM pool of ten children.

So `AppServiceProvider::configureRateLimiting()` registers two limits and both are needed:

- **by address** (300/min) is what stops a flood. A caller rotating a made-up token gets a fresh
  token bucket every request, so a per-token limit would never see the same key twice;
- **by token** (120/min) so one runaway agent cannot spend the whole address budget of a NAT it
  shares. The token is **hashed into the key**, so the cache never holds a usable credential.

It is written on the group in `routes/api.php` rather than folded into the `api` middleware group,
because `gatherMiddleware()` reports a group by name and not by contents — a limit the sweep cannot
see is a limit nothing will notice losing.

**The counters must not live in the game database.** `cache.limiter` is `file`, not the default
store, and that is not tidiness. With the default, every throttled request runs an `update` on the
`cache` table inside the same SQLite file the games are in; a concurrent burst raises
`database is locked` and answers **500**. That happened — four times in a hundred and forty requests,
against production, the first time this throttle shipped. A counter that resets every minute has no
business contending for a write lock with a turn report. `AgentApiTest` asserts the limiter's store
is not the database one, against the store itself rather than the config name so it survives a move
to Redis.

The `v1` prefix is not decoration: agents are deployed where this application cannot reach them, so a
payload change cannot ship to both at the same moment.

## `docs/reference/agent-api.md` is the published contract — change it with the API

That file is what somebody writes an agent against, and its readers are outside this repository and
cannot be redeployed with it. Treat it as part of the API surface: a change to a route, a payload
field, a status code, a message string or a rate limit is not finished until the document says so.

It is **reference** in the Diataxis sense — it describes and does not explain, and it does not
instruct either. Reasoning belongs here, in this file, and the document links to it rather than
repeating it. Everything addressed to somebody *doing* the work lives in two how-to guides beside
it, `docs/how-to/connect-an-agent-to-a-game.md` and `docs/how-to/keep-an-agent-token-safe.md`, and
they are part of the same contract: a change that alters how a client must behave belongs in one of
them. The code examples are all in the connect guide, extracted verbatim from that file and run
against production before it ships; keep that true, and note that the shell one avoids a variable
named `status` because that identifier is read-only in zsh.

It documents `~/.config/svolos/agents.json` as where an agent finds its token, because agents are run
from a workstation that has it — not from the production server. An agent is given a **base URL, a
name and a game**, and those three index the registry directly:
`registry[base_url][agent]["seats"][game]["token"]`. The registry is keyed rather than a list of
arrays so that resolution is one subscript chain instead of two linear scans — for an agent
generating throwaway code, traversal loops are places to get it wrong. The top-level key carries the
scheme and is used as given. `seats` keeps that name rather than becoming `games` because the value
is a credential for a **seat**, which is the unit this whole system is built on and what an order is
attributed to; the key alongside it is the game's short name. Each entry also carries its `seat` id,
which lets an agent name itself in a log without a request — a convenience, never a credential. The
reference describes the file; the connect guide is where an agent is told to read only its own entry
and to stop rather than fall back to another agent's, since acting as somebody else is worse than
not acting.

**Never put a real token in any of the three.** The repository is public and tokens do not expire, so one
that reaches a commit is live until somebody notices, and the history keeps it afterwards. The first
draft illustrated the `Authorization` header with a token copied out of a real registry while the
examples were being written; it was caught before the file was committed.
`tests/Feature/Agents/NoCommittedTokensTest.php` is what makes that catch repeatable — it matches the
real shape (the prefix plus exactly 48 characters of the generator's alphabet) and allows only
placeholders spelling `EXAMPLE`.

## Only entities accept orders — and that check does not live in a controller

Carried over from earlier versions of the game, and the reason the API is a second surface rather
than a second set of rules: **an order is accepted by an entity and by nothing else.** Being the
*target* of an order is a different thing and carries no such restriction.

Authorisation for submitting one is therefore a single question — *does this seat control this
entity* — and it must live in a domain action that every transport calls. The browser and `api/*` are
two ways in; if each answers the question itself, they will eventually answer differently, and the
one that drifts is the one nobody is looking at. `AgentSession::seat()` exists so game code reads the
**seat** rather than `$request->user()`: the account is one game wider than the token is scoped to,
and it is the reading that breaks first when delegation arrives.

**Entities exist now; orders still do not.** `App\Models\Entity` arrived with the units generation
stage and took the shape this file argued for — one non-nullable `game_seat_id`, no arc — so the
paragraph above is no longer advice about a table somebody might build. See [units.md](units.md).
What is still owed is the order itself and the domain action that answers *does this seat control this
entity*, which is the half that must not end up in a controller.
