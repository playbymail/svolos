<?php

namespace App\Models;

use App\Enums\GameStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One game, and the roster of seats that says who is in it.
 *
 * `short_name` is the identifier that leaves the application: it goes into turn reports and generated
 * file names, which is why it is capped at 16 characters and restricted to `[A-Z0-9-]`. The
 * uppercasing happens in validation, *before* the character check, so `run-1` is accepted and stored
 * as `RUN-1` while `run 1` is rejected — see `App\Concerns\GameValidationRules`.
 *
 * @property int $id
 * @property string $name
 * @property string $short_name
 * @property GameStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $seats_count
 * @property-read int|null $active_seats_count
 * @property-read Collection<int, GameSeat> $seats
 * @property-read Collection<int, GameSeat> $activeSeats
 */
#[Fillable(['name', 'short_name', 'status'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * The longest a game's short name may be.
     *
     * Matches the column width in `..._create_games_table.php`, and lives on the model rather than in
     * `App\Concerns\GameValidationRules` because a trait constant cannot be read through the trait's own
     * name — the rules and the tests need one place to agree on. Sixteen characters is a limit on the
     * short name's *purpose* rather than the column's capacity: it is embedded in generated file names,
     * so it has to stay short enough to sit inside one.
     */
    public const int SHORT_NAME_MAX_LENGTH = 16;

    /**
     * The model's default attribute values.
     *
     * Repeats the column default from `..._create_games_table.php` so an unsaved `new Game` already
     * reads back as `setup` instead of hitting the enum cast with a null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => GameStatus::Setup->value,
    ];

    /**
     * Get every seat at this game, retired ones included.
     *
     * This is the relation the uniqueness rule and the scoped route bindings both go through, and it
     * has to stay unfiltered for either to be right: a retired seat still blocks a second seat for the
     * same account, and a retired seat still has to be reachable through its game's URL so it can be
     * reactivated. Use `activeSeats()` when you want only the live roster.
     *
     * `game_seats.game_id` cascades on delete, so deleting a game takes these rows with it.
     *
     * @return HasMany<GameSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(GameSeat::class);
    }

    /**
     * Get the seats at this game that have not been retired.
     *
     * This exists as its own relation rather than as a `withCount` closure alias on purpose. Read
     * `withCount(['seats', 'activeSeats'])` next to the `@property-read int|null $active_seats_count`
     * above and the two obviously refer to the same thing: the count's name is derived from a relation
     * that exists, so nothing can rename one without the other going stale visibly.
     *
     * The equivalent `'seats as active_seats_count' => fn ($query) => $query->where('is_active', true)`
     * would give the same runtime result while leaving the declared property backed by nothing but a
     * string in a `withCount` array. Note that Larastan does **not** catch that: this project's
     * `phpstan.neon` does not enable `checkModelProperties`, so an undeclared model property reads as
     * `mixed` and passes at level 8 either way. The relation is the right shape because it is honest,
     * not because the analyser forces it — which is precisely why it is written down here and in
     * `.ai/rules/games.md`.
     *
     * @return HasMany<GameSeat, $this>
     */
    public function activeSeats(): HasMany
    {
        return $this->seats()->where('is_active', true);
    }

    /**
     * Scope the query to games that have not been archived.
     *
     * `Archived` is the one status with behaviour attached: an archived game stays addressable by its
     * own URL, but drops out of any list that assumes a game is still in play.
     *
     * @param  Builder<Game>  $query
     */
    #[Scope]
    protected function unarchived(Builder $query): void
    {
        $query->where('status', '!=', GameStatus::Archived);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
        ];
    }
}
