# Agent API reference

The agent API is the interface a non-human player uses to take part in a game. It is a JSON API
authenticated by a bearer token and nothing else.

This document describes the API. It does not show how to use it: for that, see
[how to connect an agent to a game](../how-to/connect-an-agent-to-a-game.md) and
[how to keep an agent token safe](../how-to/keep-an-agent-token-safe.md). For how the design works
and why, see `.ai/rules/agents.md` in this repository.

---

## Versioning

Every path is prefixed with a version: `/api/v1/…`. A version is never changed in place. A payload
change that would break a client is published under a new prefix, and the previous prefix continues
to answer.

---

## Authentication

Every request carries a token in the `Authorization` header:

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
is created. The server stores only a SHA-256 hash of it and keeps no copy of the token itself. A
lost token cannot be recovered.

Issuing a new token for a seat replaces the previous one, which stops working immediately. This is
also how a token is revoked without replacement: an administrator deletes it, and every request
using it is refused from that moment.

---

## The agent registry

`~/.config/svolos/agents.json` holds the credentials for every agent configured on one machine. It
contains live credentials and is mode `0600`.

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

A base URL, an agent name and a game short name index it directly, in that order:

```text
registry[base_url][agent name]["seats"][game]["token"]
```

| Key | Type | Description |
| --- | --- | --- |
| top level | object | Keyed by **base URL**, scheme included. |
| second level | object | Keyed by agent name. Matches `agent.name` from `GET /api/v1/me`. |
| `seats` | object | Keyed by the game's `short_name`. |
| `seats[].seat` | integer | The seat's identifier. Matches `seat.id` from `GET /api/v1/me`. |
| `seats[].token` | string | The bearer token for that seat. |

`seats[].seat` is a convenience, not a credential: the token alone decides what a request may do,
and the server is the authority on which seat that is.

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
| `429` | `Too Many Attempts.` | A rate limit was exceeded. |

A `401` is not resolved by any change to the game; a new token is required. A `403` leaves the token
intact, and the same token answers again once an administrator reactivates the seat or takes the
game out of archived status.

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

**These headers report the per-token limit only.** `X-RateLimit-Limit` is always `120`; the
per-address limit is not reflected in any header. A `429` can therefore be returned while
`X-RateLimit-Remaining` is greater than zero, which means the address limit was reached — usually
because several agents share one address.

Counters are approximate under concurrency: a burst of simultaneous requests may allow slightly more
than the stated maximum before refusing.

---

## Not yet available

The API does not currently accept orders. `GET /api/v1/me` is the only endpoint.

When order submission is added, one rule will govern it: **an order is accepted by an entity and by
nothing else.** Being the target of an order carries no such restriction.
