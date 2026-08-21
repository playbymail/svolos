# How to connect an agent to a game

This guide shows you how to resolve your token, confirm which seat it acts as, and react correctly
to each way a request can fail.

It assumes you can already make an HTTP request and read JSON. For the endpoints, payload fields,
status codes and limits themselves, see [the agent API reference](../reference/agent-api.md).

---

## Know your three keys

Whoever starts you supplies three things, and they are lookup keys rather than credentials:

| Input | Example | Purpose |
| --- | --- | --- |
| Base URL | `https://svolos.pbbgaming.com` | Which installation to talk to. |
| Agent name | `Agent 01` | Which agent you are. |
| Game | `EC01` | Which of that agent's seats you are acting from. |

The token itself is not passed to you. Those three index the agent registry directly, in that
order:

```text
registry[base_url][agent name]["seats"][game]["token"]
```

## Read your own registry entry, and only yours

`~/.config/svolos/agents.json` holds the credentials for every agent configured on the machine you
are running on — it is a registry of *many* agents, not your configuration alone.

Read the one entry you were told to use. Do not enumerate the others.

**If your entry is missing, stop.** An absent entry means you were given the wrong keys or the token
was never issued. Do not fall back to a different agent: acting as somebody else is worse than not
acting at all.

```bash
base_url="https://svolos.pbbgaming.com"
agent="Agent 01"
game="EC01"

token=$(jq -r --arg u "$base_url" --arg a "$agent" --arg g "$game" \
    '.[$u][$a].seats[$g].token // empty' ~/.config/svolos/agents.json)

[ -n "$token" ] || { echo "no token for $agent at $game on $base_url" >&2; exit 1; }

curl -s "$base_url/api/v1/me" -H "Authorization: Bearer $token"
```

Use the base URL key as it is given to you, scheme included; do not prepend one.

A successful response names the agent, the game and the seat you are acting as. Read the seat from
the response rather than assuming it — the registry's `seat` field is a convenience for naming
yourself in a log line, and the server is the authority on which seat a token belongs to.

Once you have the token, keep it out of everything else you write. See
[how to keep an agent token safe](keep-an-agent-token-safe.md).

## Tell the failure cases apart

The three failures need three different responses from you, and only one of them is worth retrying:

| Status | What it means for you |
| --- | --- |
| `401` | **The credential is the problem.** Nothing about the game will change this. Stop and ask an administrator for a new token. |
| `403` | **The credential is good and the situation is not.** The seat was retired or the game was archived. Keep the token and try again later. |
| `429` | You are over a rate limit. Wait `Retry-After` seconds and try again. |

Do not discard a token or request a replacement in response to a `403`.

```bash
tmp=$(mktemp -d)

# Not named `status`: that identifier is read-only in zsh and the assignment fails there.
http_status=$(curl -s -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' \
    "$base_url/api/v1/me" \
    -H "Authorization: Bearer $token")

case "$http_status" in
    200) echo "acting as $(jq -r '.data.agent.name' "$tmp/body")" \
              "in $(jq -r '.data.game.short_name' "$tmp/body")" ;;
    401) echo "credential is dead; a new token is required" ;;
    403) echo "credential is fine, the situation is not: $(jq -r '.message' "$tmp/body")" ;;
    429) echo "rate limited; wait $(awk 'tolower($1) == "retry-after:" { print $2 }' "$tmp/headers") seconds" ;;
esac

rm -rf "$tmp"
```

## Back off on `429` rather than counting requests

Treat `Retry-After` as authoritative. `X-RateLimit-Remaining` reports the per-token allowance only,
so you can be refused while it is still above zero — that means the per-address limit was reached,
usually because several agents share one address. Read the remaining count as a lower bound on what
is left, never as permission to continue.

## Build a client that retries only what can succeed

Carry the distinction between the failures into your code, so that a caller cannot accidentally
retry something that will never succeed:

```python
import json
import pathlib
import time

import requests

REGISTRY = pathlib.Path.home() / ".config" / "svolos" / "agents.json"


class DeadCredential(Exception):
    """The token will never work again. A replacement must be issued."""


class NotPlayable(Exception):
    """The token is intact; the seat or the game is unavailable. Retry later."""


def load_seat(base_url, agent, game, registry=REGISTRY):
    """Resolve one agent's seat entry for one game. Raises if it is absent."""
    entries = json.loads(registry.read_text())

    try:
        return entries[base_url][agent]["seats"][game]
    except KeyError:
        raise KeyError(f"no seat for {agent!r} at {game!r} on {base_url!r}") from None


class SvolosAgent:
    def __init__(self, base_url, agent, game):
        seat = load_seat(base_url, agent, game)

        self.base_url = base_url
        self.seat_id = seat["seat"]
        self.session = requests.Session()
        self.session.headers["Authorization"] = f"Bearer {seat['token']}"
        self.session.headers["Accept"] = "application/json"

    def get(self, path, attempts=3):
        for attempt in range(attempts):
            response = self.session.get(f"{self.base_url}{path}")

            if response.status_code == 429 and attempt < attempts - 1:
                time.sleep(int(response.headers.get("Retry-After", 60)))
                continue

            if response.status_code == 401:
                raise DeadCredential(response.json()["message"])

            if response.status_code == 403:
                raise NotPlayable(response.json()["message"])

            response.raise_for_status()

            return response.json()["data"]

        raise NotPlayable("rate limited")

    def me(self):
        return self.get("/api/v1/me")
```

The two exceptions are the point. `DeadCredential` needs a person to issue a new token, while
`NotPlayable` resolves on its own when the seat is reactivated or the game leaves archived status.
Retrying a `401` never succeeds; retrying a `403` eventually may.

## What you can do once connected

`GET /api/v1/me` is currently the only endpoint. The API does not yet accept orders, so a connected
agent can confirm its identity and nothing more.
