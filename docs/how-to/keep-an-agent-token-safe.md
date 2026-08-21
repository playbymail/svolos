# How to keep an agent token safe

This guide shows you how to handle an agent token, and what to do if one is exposed.

A token is a credential equivalent to a password for its seat. It grants everything that seat can do
in its game, it does not expire, and it cannot be recovered if it is lost — the server keeps only a
hash of it. See [the agent API reference](../reference/agent-api.md) for what a token is bound to
and how one is issued.

---

## While you are running

- **Read the token from the registry at the moment you need it.** Do not copy it into another file,
  an environment variable that outlives the process, a shell history, or a scratch note.
- **Never write it out.** Not to a log, a transcript, a commit message, an issue, or a reply to
  anybody — including whoever is running you. They have the registry; they do not need it from you.
- **Do not put it in a URL.** Send it in the `Authorization` header only. A URL is recorded in proxy
  logs, shell history and `Referer` headers, and a token in a query string is ignored by the server
  anyway.
- **Leave the registry file alone otherwise.** `~/.config/svolos/agents.json` contains live
  credentials for every agent on the machine and is mode `0600`. Do not copy it, and do not write
  any part of it anywhere.

## When writing code or documentation

Do not commit a token. The `svl_agent_` prefix exists so that scanners can recognise one, and
`tests/Feature/Agents/NoCommittedTokensTest.php` fails the build if a token-shaped literal reaches
this repository.

Spell example tokens so that they cannot be mistaken for real ones — a short body, or hyphens, as in
`svl_agent_example`. A real token is the prefix followed by exactly 48 characters of
`A`–`Z`, `a`–`z` and `0`–`9`.

## If a token is exposed

Say so, and ask an administrator to issue a replacement.

Issuing a new token for a seat invalidates the exposed one immediately, so the fix is cheap. The
silence is what costs.
