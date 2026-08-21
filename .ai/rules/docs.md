# The docs directory

Globs: `docs/**`

`docs/` is arranged by what a reader came for, following Diataxis: a directory per kind of document,
`docs/README.md` as the landing page, and one rule that every file obeys — **a document does one job
and links to the others**. The four kinds answer different needs, and a document that tries to serve
two serves neither.

## `reference/` describes, `how-to/` directs, and neither does the other's job

`docs/reference/` is consulted while working. It is austere: facts, tables, field lists, status
codes, error strings. No opinion, no rationale, no procedure. When reference needs an example, the
example illustrates the shape of a thing rather than walking somebody through a task.

`docs/how-to/` is read by somebody already competent who wants a result. It is action and only
action: numbered-in-spirit steps, conditional imperatives, the failure cases they will actually meet,
and their code. It assumes what reference already states rather than repeating it.

This split was made by moving working code out of `docs/reference/agent-api.md`, which had grown a
shell client, a Python client and a section of credential-handling advice inside what was otherwise a
clean API description. The advice was the tell: a reference document cannot say "do not copy this
into a log" without having stopped describing. Those became
`docs/how-to/connect-an-agent-to-a-game.md` and `docs/how-to/keep-an-agent-token-safe.md`.

A title says exactly what the guide shows, and begins with "How to". *Connecting an agent* could be
a discussion of whether to; *How to connect an agent to a game* cannot be mistaken for one.

## Explanation lives in `.ai/rules/`, not in `docs/`

Why the software is the way it is — the decisions, the alternatives rejected, the traps — is what
this directory is for, and it is written for somebody working *on* the repository. `docs/` is
written for somebody using what the repository produces. That is why there is no
`docs/explanation/`, and why a reference document that wants to justify itself links here instead of
growing a rationale section.

Do not create an empty `docs/tutorials/` to complete the set. A tutorial has to be perfectly
reliable — a learner must never get stuck — and an empty or aspirational one is worse than an
acknowledged gap. `docs/README.md` names the gap in prose; leave it that way until a real tutorial
exists.

## `docs/copy/` is not documentation and nothing reads it

`player-introduction.txt` and `hero-call-to-action.txt` are the author's drafts of the game's prose.
The text the application ships lives in `resources/js/pages/Story.svelte` and
`resources/js/pages/Welcome.svelte` — edit the components when the copy changes, and see
[frontend.md](frontend.md).

They are kept because the game's premise is written down in them and parts of the design are that
premise expressed as data: `StartingUnits::ship()` puts the engines in cargo because
`player-introduction.txt` says they burned out. See [units.md](units.md). Both files are referred
to by path from PHP docblocks and from rules files, so moving or renaming one means updating those
references in the same commit.

## One guide per goal — the deployment document became six

`DEPLOY-CADDY.md` was a 900-line document at the repository root doing all four jobs at once, and
it is the worked example of what this directory is for. It is now:

| Was | Is |
| --- | --- |
| Sections 1–10, the one-time build-out | `docs/how-to/set-up-a-production-server.md` |
| Section 12, minting the first account | `docs/how-to/create-the-first-administrator.md` |
| Sections 11 and 13, deploy and rollback | `docs/how-to/deploy-a-change.md` |
| Section 14, the symptom list | `docs/how-to/troubleshoot-a-deployment.md` |
| Sections 1, 15, 16 and every config listing | `docs/reference/production-server.md` |
| Every *why* paragraph | [deployment.md](deployment.md), here |

**The split is by goal, not by length.** Standing up a server, letting the first person in,
shipping a change and diagnosing a failure are four different tasks at four different frequencies,
and the person doing the fourth at midnight should not be scrolling through the first. A guide that
covers one goal can be read start to finish; one that covers four can only be searched.

Titles begin with "How to" and name the goal. *Deploying to Ubuntu with Caddy* named a subject and
could as easily have introduced a discussion of whether to.

The rule this replaced said the guide could stay at the repository root and could keep mixing kinds
because it was read start-to-finish at a terminal. Both halves were wrong: the root is not a
category, and it was mixing kinds *because* nobody had done the work, not because the work would
have hurt.

`scripts/deploy.sh` refers to the deploy guide by path in its header comment; keep that current.
