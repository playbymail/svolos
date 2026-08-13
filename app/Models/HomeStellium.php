<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HomeStelliumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one player's faction begins: a seat paired to a location, for one generation run.
 *
 * ## It belongs to the run, which is what makes the workflow work
 *
 * The tempting shape is a `home_location_id` column on `game_seats`, and it is wrong. A seat is
 * roster — it outlives every world a game generates — while an arrangement of homes is a **generated
 * artefact**, like the stelliums and the planets. Hanging it off the run is what gives it the whole
 * stage machine for nothing: regenerating supersedes the run and `GenerateHomeStellia::discard()`
 * drops the set, and starting the generation over deletes every run and takes every home with it
 * through the cascade. Neither is code somebody has to remember to write.
 *
 * ## The table name is spelled out, for the reason `Stellium`'s is
 *
 * Laravel's inflector pluralises `HomeStellium` to **`home_stellia`** — the `medium`/`media` rule — so
 * without `$table` this model would look for a table that appears nowhere in the schema, and a
 * `constrained()` foreign key pointed here would have to name it too.
 * `tests/Feature/GenerationModelTest.php` asserts the hazard beside the override, so the day an
 * upstream fix makes it unnecessary somebody removes it deliberately rather than by accident.
 *
 * No `#[Fillable]`, like every other generated model: nothing about a generated world arrives from
 * request input — `GenerateHomeStellia` writes these rows with a bulk insert — so there is nothing to
 * open up. Factories run unguarded, which is how tests build them.
 *
 * @property int $id
 * @property int $generation_run_id
 * @property int $game_seat_id
 * @property int $location_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GenerationRun $generationRun
 * @property-read GameSeat $gameSeat
 * @property-read Location $location
 */
class HomeStellium extends Model
{
    /** @use HasFactory<HomeStelliumFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'home_stelliums';

    /**
     * Get the run that chose this home.
     *
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * Get the seat whose player starts here.
     *
     * Not nullable: `game_seat_id` cascades on delete. A seat is retired rather than deleted, so in
     * practice this outlives somebody leaving the game — which is the point, since the arrangement is
     * a record of what the world was generated as.
     *
     * @return BelongsTo<GameSeat, $this>
     */
    public function gameSeat(): BelongsTo
    {
        return $this->belongsTo(GameSeat::class);
    }

    /**
     * Get the system this home stands at.
     *
     * A location rather than a stellium: the coordinates the roster prints and the hex distance the
     * placement rule measures both live here, and "single star" is a constraint the generator drew
     * under rather than something a foreign key could say.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
