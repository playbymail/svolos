<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StarFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One star in a stellium.
 *
 * Deliberately bare, and still bare after the planets stage: a star has no mass, class or luminosity,
 * because nothing yet needs one. What the rows are *for* is the planets that hang off them — which is
 * why they were created whole from the moment the stelliums were generated rather than left as a
 * count, since a count cannot own a planet. Give a star attributes when a stage or a rule asks for
 * them, not to fill the class out.
 *
 * `ordinal` numbers the stars within their stellium, from 1, in generated order, and is how a star is
 * named on screen — 1 is `A`, 2 is `B`, and so on up to the four a stellium can hold.
 *
 * @property int $id
 * @property int $stellium_id
 * @property int $ordinal
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $planets_count
 * @property-read Stellium $stellium
 * @property-read Collection<int, Planet> $planets
 */
class Star extends Model
{
    /** @use HasFactory<StarFactory> */
    use HasFactory;

    /**
     * Get the stellium this star belongs to.
     *
     * @return BelongsTo<Stellium, $this>
     */
    public function stellium(): BelongsTo
    {
        return $this->belongsTo(Stellium::class);
    }

    /**
     * Get the planets orbiting this star, innermost first.
     *
     * @return HasMany<Planet, $this>
     */
    public function planets(): HasMany
    {
        return $this->hasMany(Planet::class)->orderBy('ordinal');
    }

    /**
     * Get the star's name within its stellium: 1 is `A`, up to the four a stellium can hold.
     *
     * Written out rather than derived with `chr(ord('A') + ...)` because the ceiling is real: a
     * stellium holds at most four stars, `StelliumGenerator::STAR_DISTRIBUTION` is what guarantees it,
     * and the arithmetic quietly named a fifth star `E` instead of saying something had gone wrong. A
     * `LogicException` rather than an `InvalidArgumentException` because nothing is passed in — an
     * ordinal outside the four is a row that should never have been written.
     */
    public function label(): string
    {
        return match ($this->ordinal) {
            1 => 'A',
            2 => 'B',
            3 => 'C',
            4 => 'D',
            default => throw new LogicException(
                sprintf('A stellium holds at most four stars, so there is no name for ordinal %d.', $this->ordinal)
            ),
        };
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
        ];
    }
}
