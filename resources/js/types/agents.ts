/**
 * An agent account as listed on the agents screen, shaped by
 * `App\Concerns\PresentsAgents::presentAgent()`.
 *
 * An agent is an ordinary account that plays by itself: it holds seats and a game role like anybody
 * else, and the game cannot tell it apart from a person. What differs is how it authenticates — a
 * bearer token per seat instead of a password — which is why there is nothing here about verified
 * addresses, two-factor or live sessions. Those columns describe how a *person* reaches an account.
 *
 * `last_used_at_diff` is the most recent use across every one of the agent's credentials, so the
 * listing can answer "is this one still running?" without opening each agent in turn.
 */
export type Agent = {
    id: number;
    name: string;
    email: string;
    created_at: string;
    created_at_diff: string | null;
    active_seats_count: number;
    credentials_count: number;
    last_used_at_diff: string | null;
};

/**
 * One of an agent's seats and the state of that seat's credential, shaped by
 * `App\Concerns\PresentsAgents::presentSeat()`.
 *
 * **There is deliberately no token field.** `agent_credentials.token` holds a sha256 hash, so
 * putting it on the wire would ship a secret that is useless to the browser and dangerous
 * everywhere else. `has_credential` is what the screen needs: whether a token exists, not what it is.
 *
 * `can_issue` is presentation only — false on a retired seat, whose token
 * `App\Http\Middleware\AuthenticateAgent` would refuse anyway.
 * `App\Http\Controllers\Admin\AgentCredentialController` is the boundary, and a hidden button is
 * never the check.
 */
export type AgentSeat = {
    id: number;
    game: {
        id: number;
        name: string;
        short_name: string;
        status_label: string;
    };
    role_label: string;
    is_active: boolean;
    can_issue: boolean;
    has_credential: boolean;
    issued_at_diff: string | null;
    issued_by: string | null;
    last_used_at_diff: string | null;
};

/**
 * A freshly minted agent token, carried once in the page object's flash bag.
 *
 * This is the only time the plain text is ever readable: the server stores a hash of it and keeps no
 * copy, so it cannot be shown again and there is nothing to go back for. It survives one redirect
 * and is gone on the next request, which is what makes "shown once" a property of the transport
 * rather than something the screen has to remember to forget.
 */
export type AgentTokenFlash = {
    token: string;
    agent: string;
    game: string;
};
