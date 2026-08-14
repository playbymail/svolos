<?php

namespace App\Models;

use App\Enums\PlanetType;
use Carbon\CarbonImmutable;
use Database\Factories\PlanetFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One planet in orbit around one star.
 *
 * `ordinal` is the orbit, counting outward from 1, and together with the star it is the planet's whole
 * identity — there is no name. Every planet carries the same attributes whatever its type: a
 * `habitability` from 0 to 25, and deposits of the three natural resources a game is played over.
 * `type` decides which dice those were drawn from, not which of them exist.
 *
 * Like every other generated model this declares no `#[Fillable]`: nothing about a world arrives from
 * request input, the action writes these rows with a bulk insert, and the factory runs unguarded.
 *
 * There is no `game_id` and no relation to a game — see the migration for why. "Every planet in a
 * game" is the walk back up through `star.stellium.location`, which the screens do not need (they ask
 * a stellium for its own) and the tests do not need either (one game, one database).
 *
 * @property int $id
 * @property int $star_id
 * @property int $generation_run_id
 * @property int $ordinal
 * @property PlanetType $type
 * @property int $habitability
 * @property int $fuel
 * @property int $metals
 * @property int $minerals
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Star $star
 * @property-read GenerationRun $generationRun
 * @property-read Collection<int, Entity> $entities
 */
class Planet extends Model
{
    /** @use HasFactory<PlanetFactory> */
    use HasFactory;

    /**
     * Get the star this planet orbits.
     *
     * @return BelongsTo<Star, $this>
     */
    public function star(): BelongsTo
    {
        return $this->belongsTo(Star::class);
    }

    /**
     * Get the run that placed this planet.
     *
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * Get the colonies standing on this planet and the ships in orbit above it.
     *
     * One relation for both, because position is a planet whichever kind an entity is — `type` is what
     * says whether it is on the ground.
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
            'type' => PlanetType::class,
            'ordinal' => 'integer',
            'habitability' => 'integer',
            'fuel' => 'integer',
            'metals' => 'integer',
            'minerals' => 'integer',
        ];
    }
}
