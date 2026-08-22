<?php

namespace App\Models;

use App\Enums\GameRole;
use Database\Factories\GameSeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * ## The player's own three columns
 *
 * `number`, `empire_name` and `email_notifications` are what the player configures about themselves
 * at this game, and they are here rather than in a table of their own because this row already *is*
 * the account-at-a-game row.
 *
 * `number` is the empire number and is the third column kept out of `#[Fillable]`, with the strictest
 * reason of the three: it is assigned once, by `booted()`, and never posted by anybody. It counts
 * retired seats and is never reused, which follows directly from the rule above — a seat that outlives
 * its holder's time in the game has to keep the number the engine already called them by.
 *
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property int $number
 * @property string|null $empire_name
 * @property bool $email_notifications
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
#[Fillable(['user_id', 'role', 'empire_name', 'email_notifications'])]
class GameSeat extends Model
{
    /** @use HasFactory<GameSeatFactory> */
    use HasFactory;

    /**
     * The longest an empire name may be.
     *
     * Matches the column width in `..._add_player_profile_to_game_seats_table.php`, and lives on the
     * model for the reason `Game::SHORT_NAME_MAX_LENGTH` does: the validation rule and the tests need
     * one place to agree on. Sixty characters is a limit on the name's *purpose* rather than on the
     * column's capacity — an empire name is read in a list beside other empires, so it has to stay
     * short enough to sit on one line next to them.
     */
    public const int EMPIRE_NAME_MAX_LENGTH = 60;

    /**
     * The model's default attribute values.
     *
     * All three repeat column defaults from the migrations so an unsaved `new GameSeat` reads back as
     * an active player who has not asked to be mailed, instead of hitting the enum cast with a null.
     *
     * `number` is deliberately **not** here, for the reason `Game` keeps `seed` out of its own list: a
     * per-row value is not a default, and a fixed one would be worse than none — every seat would read
     * as empire 1 until somebody noticed. `booted()` assigns it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => GameRole::Player->value,
        'is_active' => true,
        'email_notifications' => false,
    ];

    /**
     * Register the model's lifecycle hooks.
     *
     * Every seat gets its empire number at creation, wherever it is created from — the administrator's
     * roster, the gamemaster's, a factory, a seeder. Assigning it in the two seat controllers instead
     * would leave every other path writing a seat with no number, which the non-nullable column would
     * then refuse with a database error rather than a message. This is the same arrangement, and the
     * same reasoning, as `Game::booted()` assigning a seed.
     *
     * The next number is the highest this **game** has ever handed out plus one, read across every seat
     * rather than the active ones: a number is not returned to the pool when its seat is retired, so
     * counting only live seats would hand the next arrival a number somebody in the game's history is
     * already known by.
     *
     * A number supplied on purpose survives, so a test can pin one. The attribute is read with
     * `getAttribute()` rather than `$seat->number` because the declared `@property int $number` is the
     * truth for a *saved* seat and a null-coalesce against it would read as dead code.
     *
     * Two seats created at the same instant would compute the same number; the unique index on
     * `(game_id, number)` is what refuses the second, rather than this hook trying to be atomic. Seats
     * are added one at a time by a person looking at a roster, so the collision is a database error on
     * a race nobody has, not a case worth serialising every seat creation for.
     */
    protected static function booted(): void
    {
        static::creating(function (GameSeat $seat): void {
            if ($seat->getAttribute('number') === null) {
                $seat->number = (int) static::query()
                    ->where('game_id', $seat->game_id)
                    ->max('number') + 1;
            }
        });
    }

    /**
     * Get the name an empire that has not been named goes by.
     *
     * "Game ACME Seat 3" — deliberately dull, because a player reading it on their own screen should
     * want to replace it.
     *
     * It is computed here rather than written into the column when the seat is created. Two reasons,
     * and the second is the one that matters: a stored copy would go stale the moment an administrator
     * renamed the game, and a null column is the only honest record that this player has not chosen a
     * name yet. The screen prefills its input with this and still knows the difference, which is why
     * this is separate from `empireName()` rather than folded into it.
     *
     * Reads `$this->game`, so a caller that already has the game in hand should hand it over with
     * `setRelation('game', $game)` rather than pay for a lazy load — `Player\GameController` does.
     */
    public function defaultEmpireName(): string
    {
        return __('Game :game Seat :seat', [
            'game' => $this->game->short_name,
            'seat' => $this->number,
        ]);
    }

    /**
     * Get the name this seat's empire goes by.
     *
     * The chosen name, or the fallback above when there is none. Anything showing an empire to
     * somebody uses this; only the screen that *edits* the name needs to tell the two apart.
     */
    public function empireName(): string
    {
        return $this->empire_name ?? $this->defaultEmpireName();
    }

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
     * Scope the query to seats that are currently running a game.
     *
     * An active seat holding `GameRole::Gamemaster` — the one thing that means "this account runs
     * this game". A retired seat grants nothing, because seats are retired rather than deleted and
     * `is_active` is what says somebody is still in the game.
     *
     * It is a scope rather than a `where` written out at each call site because the answer is asked
     * in two places that must never disagree: `App\Http\Middleware\EnsureUserRunsAGame`, which
     * gates the kit template library, and `App\Http\Middleware\HandleInertiaRequests`, which
     * decides whether the sidebar offers a link to it. A nav item that asks a slightly different
     * question than the gate is either a link that 403s or a screen nobody can find.
     *
     * @param  Builder<GameSeat>  $query
     */
    #[Scope]
    protected function activeGamemaster(Builder $query): void
    {
        $query->where('is_active', true)->where('role', GameRole::Gamemaster);
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
            'email_notifications' => 'boolean',
        ];
    }
}
