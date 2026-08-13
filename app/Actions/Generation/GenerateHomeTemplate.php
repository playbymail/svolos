<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Generation\HomeTemplate;
use App\Generation\HomeTemplateGenerator;
use App\Models\GenerationRun;

/**
 * Settles the home system every player begins in.
 *
 * The only stage that writes no rows. Every other one turns a plan into locations, stelliums, stars,
 * planets or home stelliums; this one produces a **template**, which is an input to the two stages
 * after it rather than a piece of the world — so it lives on the run beside the seed, and `discard()`
 * has nothing to throw away. See the migration that adds `generation_runs.template` for why that
 * distinction decides the schema.
 *
 * ## Two ways in, one stage
 *
 * A gamemaster either uploads a document or ticks the box to have one drawn. By the time this runs
 * the difference is already settled: `Gamemaster\GenerationController` parses an uploaded document
 * into `$run->template` before the run is made, so a null here means "nothing was uploaded, draw
 * one". Both paths end in the same `HomeTemplate`, and nothing downstream can tell which was used
 * except by the `file` the template remembers.
 *
 * Parsing happens at the edge rather than here because a malformed document is a message about a
 * *posted file*, and the run should not exist at all if the file was never usable — where a drawn
 * template cannot fail and needs the run's seed, which only exists once the run does.
 */
class GenerateHomeTemplate implements StageGeneration
{
    public function __construct(private readonly HomeTemplateGenerator $generator) {}

    /**
     * Get the stage this generation produces.
     */
    public function stage(): GenerationStage
    {
        return GenerationStage::HomeStelliaTemplate;
    }

    /**
     * Settle this run's template, drawing one if no document was uploaded.
     *
     * The assignment persists because `RunGeneration` saves the run again to store the summary this
     * returns — the same second save every stage relies on.
     *
     * @return array<string, mixed>
     */
    public function handle(GenerationRun $run): array
    {
        $template = $run->template === null
            ? $this->generator->generate($run->seed)
            : HomeTemplate::fromArray($run->template);

        $run->template = $template->toArray();

        return $template->summary();
    }

    /**
     * Throw away what a superseded run produced, which is nothing.
     *
     * The template itself stays on the superseded row on purpose. It is what that attempt *was*, the
     * way a superseded cluster run keeps the seed it drew from, and keeping it is how the screen can
     * still name the document a gamemaster rejected.
     */
    public function discard(GenerationRun $run): void
    {
        //
    }
}
