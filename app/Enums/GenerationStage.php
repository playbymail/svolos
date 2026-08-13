<?php

namespace App\Enums;

/**
 * The stages a game's world is generated in, in the order they must happen.
 *
 * **Declaration order is the dependency order.** A stage cannot be run until every stage before it
 * has been accepted, and `previous()` and `position()` both read `cases()` rather than a hand-kept
 * number, so inserting a stage in the middle reorders the workflow correctly by itself.
 *
 * Adding a case has one deliberate consequence worth knowing before you add one: a game is only
 * ready to leave setup when *every* case has an accepted run, so a new stage immediately makes every
 * unfinished game incomplete again. That is the intended behaviour — a game missing a generation
 * step is not ready to be played — and it is why the check sweeps `cases()` instead of naming the
 * last stage.
 */
enum GenerationStage: string
{
    case Cluster = 'cluster';
    case Stelliums = 'stelliums';
    case Planets = 'planets';
    /*
     * The only stage whose input is the game's **roster** rather than the stage before it: one home
     * per active player. It is last because it needs a finished world to place into — single-star
     * systems are not known until the stelliums exist — and because a player choosing where to begin
     * should be looking at somewhere that is fully described.
     */
    case HomeStellia = 'home_stellia';

    /**
     * Get the human readable label for the stage.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cluster => 'Cluster',
            /*
             * **"Stellia", not "stelliums", and the difference from the case name is deliberate.**
             * The Latin plural is what the game is played in and what every heading, toast and
             * refusal on the screen therefore says. The *case* and its backed value stay `Stelliums`
             * because they are code: the value is stored in `generation_runs.stage` and is a route
             * parameter, so renaming it would orphan every stored run and break saved URLs — and
             * `stelliums` is also the table's real name, spelled out precisely because the inflector
             * would guess `stellia`. Label is display, value is identity; only one of them is free.
             */
            self::Stelliums => 'Stellia',
            self::Planets => 'Planets',
            self::HomeStellia => 'Home stellia',
        };
    }

    /**
     * Get the sentence the screen uses to say what this stage produces.
     */
    public function description(): string
    {
        return match ($this) {
            self::Cluster => 'Scatters the locations that make up the cluster, each one a place a game can happen.',
            self::Stelliums => 'Puts a stellium — one to four stars bound by gravity — at every location.',
            self::Planets => 'Gives every star one to ten planets, ordered outward, each with its habitability and its deposits.',
            /* No unit named here: the separation is counted in hexes or through space, and the form says which. */
            self::HomeStellia => 'Gives every player a home to begin from: a single-star system, kept clear of every other.',
        };
    }

    /**
     * Get this stage's place in the order, counting from zero.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * Get the stage that has to be accepted before this one may run.
     */
    public function previous(): ?self
    {
        return self::cases()[$this->position() - 1] ?? null;
    }
}
