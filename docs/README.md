# Documentation

Documentation for Epimethean Challenge, arranged by what a reader has come for. The four kinds of
documentation — tutorials, how-to guides, reference and explanation — answer different needs, and
mixing them in one document serves none of them well.

Nothing here is generated. Each document is written and maintained by hand.

---

## How-to guides

Directions for getting something done, written for a reader who already knows what they want.

- [How to connect an agent to a game](how-to/connect-an-agent-to-a-game.md) — resolve a token from
  the registry, confirm which seat it acts as, and react correctly to each way a request can fail.
- [How to keep an agent token safe](how-to/keep-an-agent-token-safe.md) — handling a credential that
  does not expire and cannot be recovered.

Deploying the application is covered by [`DEPLOY-CADDY.md`](../DEPLOY-CADDY.md) at the repository
root, which walks a fresh Ubuntu server from nothing to a running installation.

## Reference

Descriptions to consult while working. Austere, and complete for what they cover.

- [Agent API](reference/agent-api.md) — endpoints, payload fields, authentication, error messages
  and rate limits. This is a published contract: its readers are outside this repository and cannot
  be redeployed with it, so a change to the API is not finished until this document says so.
- [Glossary](reference/glossary.md) — the terms the game is designed, built and played in, and the
  words that are reserved. A term belongs there once it is settled, whether or not anything
  implements it yet.

## Explanation

Why the software is built the way it is lives in [`.ai/rules/`](../.ai/rules/index.md), one file per
area, each written reason-first so that a later reader does not undo a decision by accident. That
audience is someone working *on* this repository rather than someone using it, which is why it sits
beside the code instead of here.

## Tutorials

There are none yet. A tutorial has to be perfectly reliable — a learner must never get stuck — and
nothing here has been through that, so nothing claims to be one. The nearest thing is the how-to
guide for connecting an agent, which assumes competence rather than teaching it.

---

## Not documentation

[`copy/`](copy/) holds the author's drafts of the game's prose:

- `player-introduction.txt` — the long-form story a first-time visitor reads.
- `hero-call-to-action.txt` — the landing page's opening pitch.

**Nothing reads these at runtime.** The text the application ships lives in
`resources/js/pages/Story.svelte` and `resources/js/pages/Welcome.svelte`; edit the components when
the copy changes. The drafts are kept because the game's premise is written down in them and parts
of the design — the burned-out engines a starting ship cannot replace, among others — are that
premise expressed as data.
