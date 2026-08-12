<?php

namespace App\Generation;

use App\Enums\PlanetType;

/**
 * Every star's planets, from one run of `PlanetGenerator`.
 *
 * A list of systems in the order the stars were counted: the first entry belongs to the first star of
 * the first location's stellium. It is a list rather than a map keyed by star id because the generator
 * is pure and has never seen a database row — pairing the plan with the cluster's stars is the
 * persisting action's job.
 */
final readonly class PlanetPlan
{
    /**
     * @param  list<PlanetSystem>  $systems  one entry per star, in the order the stars were given
     */
    public function __construct(public array $systems) {}

    /**
     * Get how many stars this plan covers.
     */
    public function count(): int
    {
        return count($this->systems);
    }

    /**
     * Get every planet of every system, in order, flattened out of the stars that hold them.
     *
     * @return list<PlanetProfile>
     */
    public function planets(): array
    {
        return array_merge(...array_map(
            fn (PlanetSystem $system): array => $system->planets,
            $this->systems,
        ));
    }

    /**
     * Get how many planets the plan places in total.
     */
    public function planetTotal(): int
    {
        return count($this->planets());
    }

    /**
     * Get how many planets there are of each type, keyed by the type's value.
     *
     * Keys run over every type there is, including any that came out at zero, so a reader can tell "no
     * gas giants this time" from "gas giants are not a thing" — the same reason `StelliumPlan::mix()`
     * fills its keys from the distribution rather than from the result.
     *
     * @return array<string, int>
     */
    public function mix(): array
    {
        $mix = array_fill_keys(array_column(PlanetType::cases(), 'value'), 0);

        foreach ($this->planets() as $planet) {
            $mix[$planet->type->value]++;
        }

        return $mix;
    }

    /**
     * Get how many of the planets are worth living on.
     */
    public function habitableTotal(): int
    {
        return count(array_filter(
            $this->planets(),
            fn (PlanetProfile $planet): bool => $planet->isHabitable(),
        ));
    }

    /**
     * Describe this plan for the run that produced it.
     *
     * @return array{planets: int, stars: int, habitable: int, types: array<string, int>}
     */
    public function summary(): array
    {
        return [
            'planets' => $this->planetTotal(),
            'stars' => $this->count(),
            'habitable' => $this->habitableTotal(),
            'types' => $this->mix(),
        ];
    }
}
