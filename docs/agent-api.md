# Agent API reference

The agent API is the interface a non-human player uses to take part in a game. It is a JSON API
authenticated by a bearer token and nothing else.

This document describes the API. For how the design works and why, see `.ai/rules/agents.md` in this
repository.

---

## Your configuration

Whoever starts you supplies three things:

| Input | Example | Purpose |
| --- | --- | --- |
| Base URL | `https://svolos.pbbgaming.com` | Which installation to talk to. |
| Agent name | `Agent 01` | Which agent you are. |
| Game | `EC01` | Which of that agent's seats you are acting from. |

Those three are lookup keys. The token itself is not passed to you; it is read from the agent
registry.

### The agent registry

`~/.config/svolos/agents.json` holds the credentials for every agent configured on this machine.

```json
{
    "https://svolos.pbbgaming.com": {
        "Agent 01": {
            "seats": {
                "EC01": {
                    "seat": 8,
                    "token": "svl_agent_EXAMPLEtokenEXAMPLEtokenEXAMPLEtokenEXAMPLEtoken"
                }
            }
        }
    }
}
```

The three keys you were given index it directly, in that order:

```text
registry[base_url][agent name]["seats"][game]["token"]
```

| Key | Type | Description |
| --- | --- | --- |
| top level | object | Keyed by **base URL**, scheme included. Use the key as given; do not prepend a scheme. |
| second level | object | Keyed by agent name. Matches `agent.name` from `GET /api/v1/me`. |
| `seats` | object | Keyed by the game's `short_name`. |
| `seats[].seat` | integer | The seat's identifier. Matches `seat.id` from `GET /api/v1/me`. |
| `seats[].token` | string | The bearer token for that seat. |

`seat` is there so you can name the seat you are acting as — in a log line, say — without a request.
It is a convenience, not a credential: the token alone decides what you can do, and the server is the
authority on which seat that is.

The file is a registry of *many* agents, not your configuration alone. Read the one entry you were
told to use. Do not enumerate the others, and do not fall back to a different agent if yours is
missing — an absent entry means you were given the wrong keys or the token was never issued, and
acting as somebody else is worse than stopping.

The file contains live credentials and is mode `0600`. Do not copy it, do not write any part of it
to a log or a transcript, and do not include a token in a message to anybody.

---

## Versioning

Every path is prefixed with a version: `/api/v1/…`. A version is never changed in place. A payload
change that would break a client is published under a new prefix, and the previous prefix continues
to answer.

---

## Authentication

Every request carries the token from your registry entry in the `Authorization` header:

```http
GET /api/v1/me HTTP/1.1
Host: svolos.pbbgaming.com
Authorization: Bearer svl_agent_EXAMPLEtokenEXAMPLEtokenEXAMPLEtokenEXAMPLEtoken
Accept: application/json
```

The scheme is case-insensitive: `Bearer` and `bearer` are both accepted.

**The header is the only place a token is read from.** A token supplied as a query parameter — 
`?token=`, `?api_token=` or any other name — is ignored, and the request is answered as though no
token were supplied at all.

### What a token is bound to

A token authenticates **one seat at one game**, not an account. An agent seated at two games holds
two tokens, and each one can act only in its own game. A token carries no other scope: within its
game, it can do everything that seat can do.

### Token format

| Property | Value |
| --- | --- |
| Prefix | `svl_agent_` |
| Total length | 58 characters |
| Character set | `A`–`Z`, `a`–`z`, `0`–`9` after the prefix |

### Token lifetime

Tokens do not expire.

A token is issued by an administrator on `/admin/agents` and is displayed **once**, at the moment it
is created. The server stores only a SHA-256 hash of it and keeps no copy of the token itself.

**A lost token cannot be recovered.** Store it when it is issued.

Issuing a new token for a seat replaces the previous one, which stops working immediately. This is
also how a token is revoked without replacement: an administrator deletes it, and every request
using it is refused from that moment.

---

## Responses

Successful responses are JSON with a top-level `data` object. Errors are JSON with a top-level
`message` string.

Responses are JSON regardless of the `Accept` header. Sending `Accept: application/json` is
supported but not required.

**No cookies are set and no session is created.** Each request is authenticated independently by its
token. There is no CSRF token, no login endpoint, and no sign-out. A session cookie obtained from
the web interface does not authenticate a request to this API.

---

## Endpoints

### `GET /api/v1/me`

Returns the identity the request's token authenticates as.

**Request**

No parameters.

**Response `200`**

```json
{
    "data": {
        "agent": {
            "id": 4,
            "name": "Cartographer"
        },
        "game": {
            "id": 1,
            "name": "EC01",
            "short_name": "EC01",
            "status": "setup",
            "status_label": "Setup"
        },
        "seat": {
            "id": 7,
            "role": "player",
            "role_label": "Player"
        }
    }
}
```

**Fields**

| Field | Type | Description |
| --- | --- | --- |
| `agent.id` | integer | Identifier of the agent's account. |
| `agent.name` | string | Name the agent is known by. Appears in rosters. |
| `game.id` | integer | Identifier of the game this token acts in. |
| `game.name` | string | Full name of the game. |
| `game.short_name` | string | Short name, at most 16 characters. Used in file names and reports. |
| `game.status` | string | One of `setup`, `active`, `paused`, `completed`, `archived`. |
| `game.status_label` | string | Human-readable form of `game.status`. |
| `seat.id` | integer | Identifier of the seat. Actions in the game are attributed to this seat. |
| `seat.role` | string | One of `player`, `gamemaster`. |
| `seat.role_label` | string | Human-readable form of `seat.role`. |

The agent's email address is not returned. It is a non-routable identifier and reaches no mailbox.

---

## Errors

| Status | `message` | Meaning |
| --- | --- | --- |
| `401` | `Provide your agent token as a bearer token.` | No `Authorization: Bearer` header was sent, or its value was empty. |
| `401` | `That agent token is not valid.` | The token is not recognised. It was never issued, was mistyped, or has been replaced or revoked. |
| `403` | `That seat has been retired.` | The token is valid, but its seat has been removed from the game's active roster. |
| `403` | `That game has been archived.` | The token is valid, but its game is no longer in play. |
| `404` | `The route … could not be found.` | No such path. |
| `405` | `The POST method is not supported for route api/v1/me. Supported methods: GET, HEAD.` | The path exists but not for that HTTP method. |
| `429` | `Too Many Attempts.` | A rate limit was exceeded. See below. |

The distinction between `401` and `403` is load-bearing for a client:

- **`401` means the credential is the problem.** Nothing about the game will change this. A new token
  is required.
- **`403` means the credential is good and the situation is not.** The token is intact and will work
  again if an administrator reactivates the seat or takes the game out of archived status. Do not
  discard the token or request a replacement in response to a `403`.

Error responses do not include stack traces or internal paths.

---

## Rate limits

Two limits apply to every request, and exceeding either returns `429`.

| Limit | Window | Applied per |
| --- | --- | --- |
| 300 requests | 1 minute | Source IP address |
| 120 requests | 1 minute | Token |

Both limits are counted before the token is examined, so refused requests count against them whether
or not the token is valid.

Every response carries the remaining allowance:

| Header | Present on | Description |
| --- | --- | --- |
| `X-RateLimit-Limit` | all responses | Maximum requests in the window. |
| `X-RateLimit-Remaining` | all responses | Requests left in the window. |
| `Retry-After` | `429` only | Seconds to wait before retrying. |
| `X-RateLimit-Reset` | `429` only | Unix timestamp when the window resets. |

**These headers report the per-token limit only.** `X-RateLimit-Limit` is always `120`; the per-address
limit is not reflected in any header. A client can therefore receive `429` while
`X-RateLimit-Remaining` is greater than zero, which means the address limit was reached — usually
because several agents share one address. Treat `Retry-After` as authoritative and the remaining
count as a lower bound on what is left.

Counters are approximate under concurrency: a burst of simultaneous requests may allow slightly more
than the stated maximum before refusing.

---

## Not yet available

The API does not currently accept orders. `GET /api/v1/me` is the only endpoint.

When order submission is added, one rule will govern it: **an order is accepted by an entity and by
nothing else.** Being the target of an order carries no such restriction.

---

## Examples

**Resolve your token and confirm it works**

```bash
base_url="https://svolos.pbbgaming.com"
agent="Agent 01"
game="EC01"

token=$(jq -r --arg u "$base_url" --arg a "$agent" --arg g "$game" \
    '.[$u][$a].seats[$g].token // empty' ~/.config/svolos/agents.json)

[ -n "$token" ] || { echo "no token for $agent at $game on $base_url" >&2; exit 1; }

curl -s "$base_url/api/v1/me" -H "Authorization: Bearer $token"
```

**Distinguish the failure cases**

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

**Python client**

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

The distinction between the two exceptions is the point: `DeadCredential` needs a person to issue a
new token, while `NotPlayable` resolves on its own when the seat is reactivated or the game leaves
archived status. Retrying a `401` never succeeds; retrying a `403` eventually may.

---

## Handling a token

A token is a credential equivalent to a password for its seat. It grants everything that seat can do
in its game, it does not expire, and it is not recoverable if lost.

- Read it from the registry at the moment you need it. Do not copy it into another file, an
  environment variable that outlives the process, a shell history, or a scratch note.
- Never write it to a log, a transcript, a commit message, an issue, or a reply to anybody —
  including whoever is running you. They have the registry; they do not need it from you.
- Do not commit it. The `svl_agent_` prefix exists so scanners can recognise one, and
  `tests/Feature/Agents/NoCommittedTokensTest.php` fails the build if a token-shaped literal reaches
  this repository.
- Do not put it in a URL. It is read from the `Authorization` header only, and a URL is recorded in
  proxy logs, shell history and `Referer` headers. A token in a query string is ignored anyway.
- If a token is exposed, say so and ask an administrator to issue a replacement. Issuing one
  invalidates the exposed token immediately, so the fix is cheap and the silence is what costs.
