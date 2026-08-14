<?php

namespace App\Models;

use App\Enums\GameRole;
use Database\Factories\GameSeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One account's place at one game, and the game role it holds there.
 *
 * ## Seats are retired, never deleted
 *
 * There is deliberately **no destroy endpoint** for a seat, and adding one would be a mistake even
 * though the screen looks incomplete without it. Engine history keeps referring to seats — a turn
 * report names the seat that submitted it — so a deleted row turns recorded history into a dangling
 * reference. Leaving a game sets `is_active = false` instead, and coming back sets it true again.
 *
 * Two things follow from that, and both are load-bearing:
 *
 * - the unique index on `(game_id, user_id)` **counts retired seats**, so an account that once had a
 *   seat can never get a second one. `App\Http\Requests\Admin\GameSeatStoreRequest` refuses the
 *   duplicate with a message that says so rather than letting the database throw;
 * - the "assignable accounts" list on the game screen excludes every account that already holds a
 *   seat, active *or* retired, because the only way back for a departed account is reactivation.
 *
 * `role` is a **game** role and carries no application permissions whatsoever — see
 * `App\Enums\GameRole`. `is_active` is kept out of `#[Fillable]` so it can only change through the
 * retire and reactivate endpoints, never as a side effect of a write that was about something else.
 *
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property GameRole $role
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read User $user
 * @property-read HomeStellium|null $homeStellium
 * @property-read AgentCredential|null $agentCredential
 * @property-read Collection<int, Entity> $entities
 */
#[Fillable(['user_id', 'role'])]
class GameSeat extends Model
{
    /** @use HasFactory<GameSeatFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * Both repeat column defaults from `..._create_game_seats_table.php` so an unsaved `new GameSeat`
     * reads back as an active player instead of hitting the enum cast with a null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => GameRole::Player->value,
        'is_active' => true,
    ];

    /**
     * Get the game this seat is at.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get the account sitting in this seat.
     *
     * Not nullable: `game_seats.user_id` cascades on delete, so deleting an account takes its seats
     * with it rather than leaving a seat nobody can be held to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get where this seat's player begins, once the home stellia stage has run.
     *
     * **The row belongs to a generation run, not to this seat**, which is why there is no
     * `home_location_id` column here and why nothing on this model has to be cleared when a world is
     * thrown away: starting the generation over deletes every run and the cascade takes the homes with
     * it, so this relation simply stops finding one.
     *
     * Only ever one, because `home_stelliums` is unique on `(generation_run_id, game_seat_id)` and a
     * game has at most one standing home stellia run — superseded runs have had their rows discarded.
     *
     * @return HasOne<HomeStellium, $this>
     */
    public function homeStellium(): HasOne
    {
        return $this->hasOne(HomeStellium::class);
    }

    /**
     * Get the bearer token an agent uses to act as this seat, if one has been issued.
     *
     * At most one, because `agent_credentials.game_seat_id` is unique: minting a replacement
     * overwrites the row rather than leaving two live tokens for one seat.
     *
     * The relation is on the seat rather than on the account because a token authenticates as *this
     * place at this game* and nothing wider. Note that a credential here does not make the account an
     * agent — `users.is_agent` answers that, and the two come apart the moment an agent is delegated
     * a person's seat. See `.ai/rules/agents.md`.
     *
     * @return HasOne<AgentCredential, $this>
     */
    public function agentCredential(): HasOne
    {
        return $this->hasOne(AgentCredential::class);
    }

    /**
     * Get everything this seat controls: its colonies and its ships.
     *
     * The one relation on this model that is about playing the game rather than about holding a place
     * at it, and it is here because a seat is where control lives — an entity accepts orders from the
     * seat that controls it and from nowhere else. Retiring a seat leaves them standing, which is the
     * point of retiring rather than deleting.
     *
     * @return HasMany<Entity, $this>
     */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => GameRole::class,
            'is_active' => 'boolean',
        ];
    }
}
