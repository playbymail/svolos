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
premise expressed as data: `StartingAssets::ship()` puts the engines in cargo because
`player-introduction.txt` says they burned out. See [assets.md](assets.md). Both files are referred
to by path from PHP docblocks and from rules files, so moving or renaming one means updating those
references in the same commit.

## `DEPLOY-CADDY.md` stays at the repository root

It is a how-to guide by any reading, and it still does not live in `docs/how-to/`. Its audience
arrives at the repository root looking for it, it is referenced from outside this repository, and it
carries its own reference and explanation sections about one specific server. `docs/README.md` links
to it. Moving it would break more than the tidiness is worth.
