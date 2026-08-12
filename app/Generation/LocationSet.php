<?php

namespace App\Generation;

/**
 * The cluster one run of `ClusterGenerator` produced, in the order it was placed.
 *
 * The order is part of the output rather than an accident of iteration: a location's ordinal is its
 * place in this list, so the same seed always numbers the cluster the same way.
 *
 * `summary()` is what gets stored on the run. It is computed here, from the points themselves, rather
 * than being reported by the generator's own bookkeeping — the realised minimum separation is a fact
 * about the cluster, and measuring it after the fact is what makes it worth storing.
 */
final readonly class LocationSet
{
    /**
     * @param  list<Coordinates>  $coordinates  in the order they were placed
     * @param  int  $attempts  how many candidate points were drawn to place them
     */
    public function __construct(
        public array $coordinates,
        public int $attempts,
    ) {}

    /**
     * Get how many locations are in the cluster.
     */
    public function count(): int
    {
        return count($this->coordinates);
    }

    /**
     * Get the smallest distance between any two locations in the cluster.
     *
     * Zero for a cluster of fewer than two, which cannot occur from the generator but keeps the method
     * total. The comparison runs on squared distances and only the winner takes a square root.
     */
    public function minimumSeparation(): float
    {
        $smallest = null;

        foreach ($this->coordinates as $index => $coordinates) {
            foreach (array_slice($this->coordinates, $index + 1) as $other) {
                $squared = $coordinates->squaredDistanceTo($other);

                if ($smallest === null || $squared < $smallest) {
                    $smallest = $squared;
                }
            }
        }

        return $smallest === null ? 0.0 : sqrt($smallest);
    }

    /**
     * Get the distance from the centre to the outermost location.
     */
    public function maximumRadius(): float
    {
        $radii = array_map(fn (Coordinates $coordinates): float => $coordinates->radius(), $this->coordinates);

        return $radii === [] ? 0.0 : max($radii);
    }

    /**
     * Describe this cluster for the run that produced it.
     *
     * @return array{locations: int, attempts: int, minimum_separation: float, maximum_radius: float}
     */
    public function summary(): array
    {
        return [
            'locations' => $this->count(),
            'attempts' => $this->attempts,
            'minimum_separation' => round($this->minimumSeparation(), 3),
            'maximum_radius' => round($this->maximumRadius(), 3),
        ];
    }
}
