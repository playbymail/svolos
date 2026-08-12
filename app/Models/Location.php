<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One place in a game's cluster: an integer point inside a sphere of radius 15.
 *
 * Everything true of a location is true because `App\Generation\ClusterGenerator` made it so — inside
 * the sphere, never the origin, at least 2 away from every other location in the same game — and the
 * generator is where those rules are stated and tested. The two unique keys in the migration are the
 * database's share of it: no two locations in a game hold the same point or the same ordinal.
 *
 * `ordinal` is placement order within the run that produced the cluster, so the same seed numbers the
 * cluster the same way every time and the screen has a stable name for each location.
 *
 * @property int $id
 * @property int $game_id
 * @property int $generation_run_id
 * @property int $ordinal
 * @property int $x
 * @property int $y
 * @property int $z
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Game $game
 * @property-read GenerationRun $generationRun
 * @property-read Stellium|null $stellium
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Get the game whose cluster this location is part of.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Get the run that placed this location.
     *
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * Get the stellium at this location.
     *
     * Nullable, and the nullability is the workflow rather than an edge case: between accepting the
     * cluster and running the stellium generator, every location in the game has none.
     *
     * @return HasOne<Stellium, $this>
     */
    public function stellium(): HasOne
    {
        return $this->hasOne(Stellium::class);
    }

    /**
     * Get this location's distance from the centre of the cluster.
     *
     * Presented rather than stored: it is a function of three columns that never change once written,
     * so a column for it could only ever disagree with them.
     */
    public function radius(): float
    {
        return sqrt($this->x ** 2 + $this->y ** 2 + $this->z ** 2);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'x' => 'integer',
            'y' => 'integer',
            'z' => 'integer',
        ];
    }
}
