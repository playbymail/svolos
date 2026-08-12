<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StelliumFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of one to four stars bound by gravity, at one location.
 *
 * ## The table name is spelled out, and must stay that way
 *
 * Laravel's inflector pluralises `Stellium` to **`Stellia`** — the same rule that turns `medium` into
 * `media` — so without `$table` this model would look for a table that does not exist, and the error
 * would name `stellia`, a word that appears nowhere in the schema. The same trap is why
 * `..._create_stars_table.php` passes the table name to `constrained('stelliums')`, and why a future
 * `{stellium}` route parameter inside `Route::scopeBindings()` would need an explicit relation name:
 * scoped binding derives the relation as `Str::plural(Str::camel('stellium'))`, which is `stellia`.
 *
 * There is no `star_count` column. The stars are rows — the planets stage hangs off them — so a count
 * would be a second copy of `stars()->count()`, and `withCount('stars')` is what the screens use.
 *
 * @property int $id
 * @property int $location_id
 * @property int $generation_run_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $stars_count
 * @property-read Location $location
 * @property-read GenerationRun $generationRun
 * @property-read Collection<int, Star> $stars
 */
class Stellium extends Model
{
    /** @use HasFactory<StelliumFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'stelliums';

    /**
     * Get the location this stellium sits at.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the run that placed this stellium.
     *
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * Get the stars bound together in this stellium, in their generated order.
     *
     * @return HasMany<Star, $this>
     */
    public function stars(): HasMany
    {
        return $this->hasMany(Star::class)->orderBy('ordinal');
    }
}
