<?php

namespace App\Models;

use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStage;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One invocation of one generator, and the seed it drew from.
 *
 * This is the record that makes a generated game explicable. A run keeps its seed whatever becomes of
 * it, so an accepted stage can be replayed exactly — feed `seed` back through the generator named by
 * `stage` and the same locations come out — and the runs a gamemaster rejected on the way still say
 * which numbers were tried. Nothing here is deleted except with the game it belongs to.
 *
 * ## Status is derived, artefacts are not kept
 *
 * There is no status column: `accepted_at` and `superseded_at` say it all, and `status()` reads them
 * the way `Invitation::status()` reads its own timestamps. A **superseded** run is one that was
 * regenerated past; its row survives while the locations or stelliums it produced are gone, because
 * only one set of those can be the game's at a time. That asymmetry is the design — the attempt is
 * history, its output was never the game's.
 *
 * @property int $id
 * @property int $game_id
 * @property GenerationStage $stage
 * @property int $seed
 * @property int $attempt
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Game $game
 * @property-read Collection<int, Location> $locations
 * @property-read Collection<int, Stellium> $stelliums
 */
class GenerationRun extends Model
{
    /** @use HasFactory<GenerationRunFactory> */
    use HasFactory;

    /**
     * Get the game this run generated part of.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get the locations this run produced, if it was a cluster run that is still standing.
     *
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Get the stelliums this run produced, if it was a stellium run that is still standing.
     *
     * @return HasMany<Stellium, $this>
     */
    public function stelliums(): HasMany
    {
        return $this->hasMany(Stellium::class);
    }

    /**
     * Get the derived status of this run.
     *
     * Accepted beats superseded, which cannot both be true today and would still be answered the
     * honest way if some later change made it possible: a run that was accepted produced the game.
     */
    public function status(): GenerationRunStatus
    {
        return match (true) {
            $this->accepted_at !== null => GenerationRunStatus::Accepted,
            $this->superseded_at !== null => GenerationRunStatus::Superseded,
            default => GenerationRunStatus::Pending,
        };
    }

    /**
     * Determine whether this run is still waiting to be accepted or regenerated past.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->superseded_at === null;
    }

    /**
     * Determine whether this run is the one that produced what the game currently has.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Scope the query to runs that are still standing — the pending one and the accepted one.
     *
     * Superseded runs are excluded here rather than filtered at every call site, because they are the
     * only ones whose artefacts no longer exist: a screen or a check that wants "what this game has"
     * always means these.
     *
     * @param  Builder<GenerationRun>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => GenerationStage::class,
            'seed' => 'integer',
            'attempt' => 'integer',
            'summary' => 'array',
            'accepted_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
