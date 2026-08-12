<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One star in a stellium.
 *
 * Deliberately bare. A star has no mass, class or luminosity yet — those arrive with the stage that
 * gives it planets — but the rows exist from the moment the stelliums are generated, because the next
 * generator assigns planets to *stars*, and a count cannot own a planet.
 *
 * `ordinal` numbers the stars within their stellium, from 1, in generated order.
 *
 * @property int $id
 * @property int $stellium_id
 * @property int $ordinal
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Stellium $stellium
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
