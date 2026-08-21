<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;

/**
 * Finds the generation that belongs to a stage.
 *
 * This is what lets one controller and one pair of routes serve every stage: the stage arrives as a
 * route parameter, and the work behind it is looked up here rather than branched on at the edge.
 *
 * The lookup is a `match` over the enum on purpose. Adding a case to `GenerationStage` without adding
 * it here is a static-analysis error and, failing that, an `UnhandledMatchError` the first time the
 * stage is run — both of which are better than a silently missing stage that reads as "generated
 * nothing".
 */
class StageGenerationRegistry
{
    public function __construct(
        private readonly GenerateCluster $cluster,
        private readonly GenerateStelliums $stelliums,
        private readonly GenerateHomeTemplate $homeTemplate,
        private readonly GenerateHomeStellia $homeStellia,
        private readonly GeneratePlanets $planets,
        private readonly GenerateUnits $units,
    ) {}

    /**
     * Get the generation that produces a stage.
     */
    public function for(GenerationStage $stage): StageGeneration
    {
        return match ($stage) {
            GenerationStage::Cluster => $this->cluster,
            GenerationStage::Stelliums => $this->stelliums,
            GenerationStage::HomeStelliaTemplate => $this->homeTemplate,
            GenerationStage::HomeStellia => $this->homeStellia,
            GenerationStage::Planets => $this->planets,
            GenerationStage::Assets => $this->units,
        };
    }
}
