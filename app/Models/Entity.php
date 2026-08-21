<?php

namespace App\Models;

use App\Enums\EntityType;
use Carbon\CarbonImmutable;
use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A colony or a ship: the only kind of thing in this game that accepts orders.
 *
 * It accepts them from the seat that controls it and from nowhere else, and the check that says so
 * belongs in a domain action every transport calls — the browser and `api/*` are two ways in, and if
 * each answers the question itself they will eventually answer it differently. Being the *target* of
 * an order carries no such restriction. See `.ai/rules/agents.md`, which wrote that rule down before
 * this class existed.
 *
 * ## One seat, no arc
 *
 * `game_seat_id` is the whole of control. There is no owning `User`, no polymorphic owner and above
 * all no `(Player | Agent)` exclusive arc — an agent here is a `User` with `is_agent` set holding an
 * ordinary seat, so the two nullable keys and the check constraint that arc needed have nothing to
 * express. Seats are retired rather than deleted, so an entity outlives its player leaving the game.
 *
 * ## Placed by a run, or built in play
 *
 * `generation_run_id` is nullable and that is the only nullable run key in the schema. These first
 * entities were written by the units stage and go when it is regenerated or the generation is
 * started over; anything built during play belongs to no run and is nobody's artefact. The migration
 * has the argument.
 *
 * No `#[Fillable]`, like every other model a generator writes: `GenerateUnits` inserts these in bulk
 * and nothing about them arrives from request input.
 *
 * @property int $id
 * @property int $game_seat_id
 * @property int $planet_id
 * @property int|null $generation_run_id
 * @property EntityType $type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GameSeat $gameSeat
 * @property-read Planet $planet
 * @property-read GenerationRun|null $generationRun
 * @property-read Collection<int, Unit> $units
 */
class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory;

    /**
     * Get the seat that controls this entity.
     *
     * Not nullable: `game_seat_id` cascades on delete, and a seat is the grain control is expressed
     * at because control is per-game while an account is not.
     *
     * @return BelongsTo<GameSeat, $this>
     */
    public function gameSeat(): BelongsTo
    {
        return $this->belongsTo(GameSeat::class);
    }

    /**
     * Get the planet this entity stands at.
     *
     * A colony is on it and a ship is in orbit above it; `type` is what says which, because both are
     * at the same place as far as anything that reads position is concerned.
     *
     * @return BelongsTo<Planet, $this>
     */
    public function planet(): BelongsTo
    {
        return $this->belongsTo(Planet::class);
    }

    /**
     * Get the run that placed this entity, if a run placed it.
     *
     * Null for anything built during play — see the class docblock.
     *
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * Get everything this entity owns.
     *
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
        ];
    }
}
