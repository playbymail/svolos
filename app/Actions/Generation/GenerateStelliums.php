<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\StelliumGenerator;
use App\Models\GenerationRun;
use App\Models\Location;
use App\Models\Star;
use App\Models\Stellium;

/**
 * Writes the stelliums a stellium run produced, and the stars in them.
 *
 * The plan is a list of star counts in ordinal order, so it is paired with the game's locations read in
 * the same order — `Game::locations()` orders by ordinal for exactly this. Pairing by position rather
 * than by id is what keeps the generator pure: it has never seen a row.
 *
 * Two bulk inserts, not two hundred saves: the stelliums go in first, then their ids are read back to
 * build the stars. Reading them back is unavoidable — the stars need ids that only the database can
 * give — but it is one query, not one per stellium.
 */
class GenerateStelliums implements StageGeneration
{
    public function __construct(private readonly StelliumGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::Stelliums;
    }

    /**
     * Put a stellium at every location of this run's game, and stars in every stellium.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $locations = $run->game->locations()->get();

        $plan = $this->generator->generate($run->seed, $locations->count());

        $now = now();

        Stellium::query()->insert(
            $locations->map(fn (Location $location): array => [
                'location_id' => $location->id,
                'generation_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        /* Read back in the locations' own order, so position `n` of the plan lands on location `n`. */
        $stelliumIds = Stellium::query()
            ->whereIn('location_id', $locations->modelKeys())
            ->orderBy('location_id')
            ->pluck('id', 'location_id');

        $stars = [];

        foreach ($locations->values() as $index => $location) {
            $count = $plan->starCounts[$index] ?? 1;

            for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
                $stars[] = [
                    'stellium_id' => $stelliumIds[$location->id],
                    'ordinal' => $ordinal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        Star::query()->insert($stars);

        return $plan->summary();
    }

    /**
     * Throw away the stelliums a superseded run placed.
     *
     * The stars go with them through `stars.stellium_id`, which cascades in the database — a mass
     * delete does not fire model events, so the cascade is doing the work here rather than Eloquent.
     */
    public function discard(GenerationRun $run): void
    {
        $run->stelliums()->delete();
    }
}
