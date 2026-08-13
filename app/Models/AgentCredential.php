<?php

namespace App\Models;

use Database\Factories\AgentCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The bearer token one seat's agent authenticates to `api/*` with.
 *
 * **`token` holds a sha256 hash, never the token itself.** The plain text is generated once in
 * `App\Actions\Agents\IssueAgentCredential`, handed back to the screen that asked for it, and then
 * dropped. A dump of this table yields nothing an agent could authenticate with, in the same way a
 * dump of `users` yields no usable password. This is the invitation token scheme applied to a
 * different problem — see `App\Models\Invitation` and `.ai/rules/invitations.md`.
 *
 * ## The credential belongs to a seat, not to an account
 *
 * A seat is one account's place at one game, so a token is scoped to a single game by construction:
 * there is no ability list, no scope string, and nothing to get wrong when an agent is seated at a
 * second game, because that seat gets its own credential. It also leaves room for an agent that
 * drives a *person's* seat — a delegate rather than a participant — which becomes a row here rather
 * than a change to this table.
 *
 * The consequence to know before touching anything: a token cannot be recovered, so minting a
 * replacement is the only revocation there is. `game_seat_id` is unique, so the new row overwrites
 * the old one and the previous token stops working the moment a fresh one is issued.
 *
 * @property int $id
 * @property int $game_seat_id
 * @property string $token
 * @property int|null $issued_by_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameSeat $gameSeat
 * @property-read User|null $issuedBy
 */
#[Hidden(['token'])]
class AgentCredential extends Model
{
    /** @use HasFactory<AgentCredentialFactory> */
    use HasFactory;

    /**
     * The prefix every plain-text agent token carries.
     *
     * Secret scanners key on fixed prefixes, so a token pasted into a commit, a log or an issue is
     * recognisable as this application's credential rather than as an anonymous blob of base62.
     */
    public const string TOKEN_PREFIX = 'svl_agent_';

    /**
     * Generate a fresh plain-text agent token.
     *
     * The caller is responsible for showing this once and storing only `hashToken()` of it. 48
     * random characters behind the prefix is far past guessing range, which is what lets the token
     * be the sole credential on an unauthenticated API request.
     */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX.Str::random(48);
    }

    /**
     * Hash a plain-text agent token into the value stored in the `token` column.
     *
     * Deliberately a plain unsalted sha256 rather than `Hash::make()`, for the reason
     * `Invitation::hashToken()` gives: the column has to be *searchable*, because a request arrives
     * carrying the token and nothing else, and a per-row salt would force a scan of every credential
     * on every API call. A password hash's slowness buys nothing against 48 random characters from
     * our own generator — there is no dictionary to run against it.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Get the seat this credential authenticates as.
     *
     * Not nullable: `agent_credentials.game_seat_id` cascades on delete. Seats are retired rather
     * than deleted, so in practice the row outlives any departure — but an account being deleted
     * takes its seats and therefore its credentials with it, which is the outcome wanted.
     *
     * @return BelongsTo<GameSeat, $this>
     */
    public function gameSeat(): BelongsTo
    {
        return $this->belongsTo(GameSeat::class);
    }

    /**
     * Get the administrator who minted the token currently stored here.
     *
     * Nullable, and not only because the column is: `nullOnDelete` means deleting an administrator
     * leaves the credentials they issued working, with nobody attached. Revoking those is a separate
     * decision from removing the person who handed them out.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
