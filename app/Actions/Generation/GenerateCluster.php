<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\ClusterGenerator;
use App\Generation\Coordinates;
use App\Models\GenerationRun;

/**
 * Writes the locations a cluster run produced.
 *
 * The generator decides everything about the cluster; this only puts it in the database. The
 * separation between the two is the point: `ClusterGenerator` can be tested exhaustively without a
 * database, and this class has nothing left to get wrong except the writing.
 *
 * Rows are inserted in one statement rather than one at a time — a hundred inserts inside a request is
 * a hundred round trips for no gain — which is also why the timestamps are set by hand: a bulk insert
 * does not go through the model's own timestamping.
 */
class GenerateCluster implements StageGeneration
{
    public function __construct(private readonly ClusterGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::Cluster;
    }

    /**
     * Scatter this run's cluster and write it.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $cluster = $this->generator->generate($run->seed);

        $now = now();

        $run->locations()->insert(
            array_map(fn (int $index, Coordinates $coordinates): array => [
                'game_id' => $run->game_id,
                'generation_run_id' => $run->id,
                /* The ordinal is the location's place in the generated order, counting from one. */
                'ordinal' => $index + 1,
                'x' => $coordinates->x,
                'y' => $coordinates->y,
                'z' => $coordinates->z,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($cluster->coordinates), $cluster->coordinates)
        );

        return $cluster->summary();
    }

    /**
     * Throw away the locations a superseded run placed.
     *
     * A cluster can only be regenerated while it is still pending, so there are never stelliums
     * hanging off these rows — and if a later change makes that possible, `stelliums.location_id`
     * cascades and they go too.
     */
    public function discard(GenerationRun $run): void
    {
        $run->locations()->delete();
    }
}
