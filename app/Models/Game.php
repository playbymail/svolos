<?php

namespace App\Models;

use App\Enums\GameRole;
use App\Enums\GameStatus;
use App\Enums\GenerationStage;
use App\Enums\GenerationStageState;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * One game, and the roster of seats that says who is in it.
 *
 * `short_name` is the identifier that leaves the application: it goes into turn reports and generated
 * file names, which is why it is capped at 16 characters and restricted to `[A-Z0-9-]`. The
 * uppercasing happens in validation, *before* the character check, so `run-1` is accepted and stored
 * as `RUN-1` while `run 1` is rejected — see `App\Concerns\GameValidationRules`.
 *
 * `seed` is the number every random decision in the game is drawn from, and it is assigned once, at
 * creation, by `randomSeed()`. It is out of `#[Fillable]` for the same reason `GameSeat::$is_active`
 * is: it changes only through the two seed endpoints, never as a side effect of a save that was about
 * a name or a status. Re-seeding a game that has already started would silently rewrite the run its
 * turn reports describe, so both endpoints refuse it once the game has left `GameStatus::Setup`.
 *
 * @property int $id
 * @property string $name
 * @property string $short_name
 * @property int $seed
 * @property GameStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $seats_count
 * @property-read int|null $active_seats_count
 * @property-read Collection<int, GameSeat> $seats
 * @property-read Collection<int, GameSeat> $activeSeats
 * @property-read Collection<int, GenerationRun> $generationRuns
 * @property-read Collection<int, Location> $locations
 * @property-read Collection<int, Stellium> $stelliums
 * @property-read int|null $locations_count
 * @property-read int|null $stelliums_count
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
     * The lowest seed a game may carry.
     *
     * Zero is a real seed rather than a stand-in for "unset": the column is not nullable and every
     * game has one from the moment it is created, so nothing has to reserve a value to mean absence.
     */
    public const int SEED_MIN = 0;

    /**
     * The highest seed a game may carry.
     *
     * This is the width of PHP's Mersenne Twister seed, not an arbitrary ceiling. `Random\Engine\Mt19937`
     * — and `mt_srand()` before it — takes a 32-bit unsigned seed, so `[0, 4294967295]` is exactly the
     * set of values that produce distinct sequences. Allowing anything wider would let two different
     * numbers name the same game, and a seed exists to be the name of a game's randomness.
     *
     * It matches the `unsignedInteger` column in `..._add_seed_to_games_table.php`; change one and you
     * must change the other.
     */
    public const int SEED_MAX = 4294967295;

    /**
     * The model's default attribute values.
     *
     * Repeats the column default from `..._create_games_table.php` so an unsaved `new Game` already
     * reads back as `setup` instead of hitting the enum cast with a null.
     *
     * `seed` is deliberately **not** here: a random value is not a default, and a fixed one would be
     * far worse than none — every game would share it until somebody noticed. `booted()` assigns it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => GameStatus::Setup->value,
    ];

    /**
     * Draw a seed for a new game.
     *
     * `random_int()` rather than `rand()` or `mt_rand()`: it reads the platform's CSPRNG, which needs no
     * seeding of its own and carries no global state a test or a queue worker could have set. That is
     * the whole idiom — a source that cannot be seeded picks the seed for the engine that can.
     *
     * The range is the engine's own (see `SEED_MAX`), so every value this returns names a different
     * game and every value is one an administrator is allowed to type back in.
     */
    public static function randomSeed(): int
    {
        return random_int(self::SEED_MIN, self::SEED_MAX);
    }

    /**
     * Register the model's lifecycle hooks.
     *
     * Every game gets a seed at creation, wherever it is created from — the administrator's form, a
     * factory, a seeder, a console command. Assigning it in the controller instead would leave every
     * other path writing a game with no seed at all, which the non-nullable column would then refuse
     * with a database error rather than a message.
     *
     * A seed that was supplied on purpose survives: a test pinning a known seed, or a fixture that has
     * to reproduce a recorded run, is not overwritten on its way to the database. The attribute is read
     * with `getAttribute()` rather than `$game->seed`, because the declared `@property int $seed` is the
     * truth for a *saved* game and a null-coalesce against it would read as dead code.
     */
    protected static function booted(): void
    {
        static::creating(function (Game $game): void {
            if ($game->getAttribute('seed') === null) {
                $game->seed = self::randomSeed();
            }
        });
    }

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
     * Get every generation run this game has had, rejected attempts included.
     *
     * Ordered oldest first, which is the order the derived state below reads them in and the order the
     * screen lists an attempt history in. Superseded runs are **not** filtered out here: they are the
     * record of which seeds were tried, and `GenerationRun::standing()` is what excludes them when a
     * caller means "what this game currently has".
     *
     * @return HasMany<GenerationRun, $this>
     */
    public function generationRuns(): HasMany
    {
        return $this->hasMany(GenerationRun::class)->orderBy('id');
    }

    /**
     * Get the locations that make up this game's cluster, in their generated order.
     *
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class)->orderBy('ordinal');
    }

    /**
     * Get the stelliums in this game's cluster, through the locations they sit at.
     *
     * @return HasManyThrough<Stellium, Location, $this>
     */
    public function stelliums(): HasManyThrough
    {
        return $this->hasManyThrough(Stellium::class, Location::class);
    }

    /**
     * Get the run that produced what this game currently has for a stage, if any.
     *
     * At most one run per stage is ever standing: generating again supersedes the pending one, and an
     * accepted one cannot be regenerated past without starting the whole generation over.
     *
     * Reads the loaded collection rather than querying, so a screen asking about every stage costs one
     * query in total. Load `generationRuns` before calling it in a loop.
     */
    public function generationRunFor(GenerationStage $stage): ?GenerationRun
    {
        return $this->generationRuns
            ->first(fn (GenerationRun $run): bool => $run->stage === $stage && $run->superseded_at === null);
    }

    /**
     * Determine whether a stage has been accepted.
     */
    public function hasAcceptedGeneration(GenerationStage $stage): bool
    {
        return $this->generationRunFor($stage)?->isAccepted() === true;
    }

    /**
     * Get where a stage stands for this game.
     *
     * **This is derived, and there is deliberately no column for it.** The runs already say all of it,
     * and a stored stage could disagree with the rows it summarised — the failure would be a game whose
     * screen offers a button the server refuses, or hides one it would allow.
     *
     * A stage is locked until the stage before it has been accepted, which is what makes the order in
     * `GenerationStage` the workflow rather than a convention.
     */
    public function generationStateFor(GenerationStage $stage): GenerationStageState
    {
        $previous = $stage->previous();

        if ($previous !== null && ! $this->hasAcceptedGeneration($previous)) {
            return GenerationStageState::Locked;
        }

        $run = $this->generationRunFor($stage);

        return match (true) {
            $run === null => GenerationStageState::Ready,
            $run->isAccepted() => GenerationStageState::Accepted,
            default => GenerationStageState::Review,
        };
    }

    /**
     * Get the first stage of this game's generation that has not been accepted yet.
     *
     * Null means the world is finished. This is what the message refusing an `Active` status names, so
     * that a gamemaster is told which step is missing rather than that something is.
     */
    public function firstUnfinishedGenerationStage(): ?GenerationStage
    {
        foreach (GenerationStage::cases() as $stage) {
            if (! $this->hasAcceptedGeneration($stage)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Determine whether every generation stage has been accepted.
     */
    public function isGenerationComplete(): bool
    {
        return $this->firstUnfinishedGenerationStage() === null;
    }

    /**
     * Count the active players who have nowhere to begin.
     *
     * Zero for a game whose home stellia stage has been accepted — that stage places every player
     * seated at the time — and non-zero exactly when somebody has been **seated since**, which is the
     * one way a fully generated game can still have a player with no home. `GameValidationRules` uses
     * it to keep such a game out of `GameStatus::Active`.
     *
     * A live query rather than a read of `activeSeats`, because the callers are validation rules that
     * run against whatever the database says now, and because loading every seat with its home to count
     * the ones missing would be the expensive way round.
     *
     * Gamemasters and retired seats are not counted, for the same reason `GenerateHomeStellia` does not
     * place them: a gamemaster runs the game rather than playing it, and somebody who has left has
     * nowhere to need.
     */
    public function playersWithoutHomeStellium(): int
    {
        return $this->activeSeats()
            ->where('role', GameRole::Player)
            ->whereDoesntHave('homeStellium')
            ->count();
    }

    /**
     * Determine whether any generator has been run against this game yet.
     *
     * This is what closes the base seed: once a number has been drawn from, editing it would change
     * nothing that exists. Starting the generation over deletes every run, which opens it again.
     */
    public function hasGenerationRuns(): bool
    {
        if ($this->relationLoaded('generationRuns')) {
            return $this->generationRuns->isNotEmpty();
        }

        /*
         * A list asking this of every row should eager load — `Admin\GameController::index()` does —
         * but answering with a query rather than lazily loading every run keeps a caller that forgot
         * from being wrong as well as slow.
         */
        return $this->generationRuns()->exists();
    }

    /**
     * Get the attempt number the next run of a stage should carry.
     *
     * Counts superseded runs, because the number is how many times a gamemaster has asked — "attempt 3"
     * has to keep meaning the third try even though the first two produced nothing that still exists.
     */
    public function nextGenerationAttemptFor(GenerationStage $stage): int
    {
        return $this->generationRuns
            ->where('stage', $stage)
            ->max('attempt') + 1;
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
            'seed' => 'integer',
            'status' => GameStatus::class,
        ];
    }
}
