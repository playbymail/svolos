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
    /*
     * The one stage that generates nothing about the map: it settles what every player's home system
     * will look like, either from a document the gamemaster uploads or drawn from the seed. It sits
     * here because the two stages after it both read it — the homes are chosen knowing what a home
     * is worth, and the planets stage copies it into every home system rather than drawing one.
     */
    case HomeStelliaTemplate = 'home_stellia_template';
    /*
     * The only stage whose input is the game's **roster** rather than the stage before it: one home
     * per active player. It needs the stelliums, because a home stands at a single-star system and
     * those are not known until they exist, and it comes before the planets because the planets
     * stage reads the arrangement it chose — a home system is copied from the template rather than
     * drawn, so which systems are homes has to be settled first.
     */
    case HomeStellia = 'home_stellia';
    case Planets = 'planets';
    /*
     * The one stage that puts something on the map rather than drawing the map: every player's colony
     * on their home world, and the ship that carried them there in orbit above it, each with the
     * units it begins holding. It is last because it needs somewhere to stand — a home says *which*
     * system, and the planets stage is what turns that system into worlds one of which is the home
     * world. It is also the only stage that draws nothing at all: the kit is the same for every
     * player, which is a fairness rule rather than an oversight. See `App\Generation\StartingUnits`.
     */
    case Assets = 'assets';

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
            self::HomeStelliaTemplate => 'Home stellia template',
            self::HomeStellia => 'Home stellia',
            self::Planets => 'Planets',
            /*
             * **"Units", and the difference from the case name is the same trade `Stelliums`
             * makes.** The glossary settled `unit` as the word for the countable thing an entity
             * holds, so that is what the screen says. The *case* and its backed value stay `Assets`
             * because they are code: the value is stored in `generation_runs.stage` and is a route
             * parameter, so renaming it would orphan every stored run and break saved URLs.
             */
            self::Assets => 'Units',
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
            self::HomeStelliaTemplate => 'Settles the home system every player begins in: upload one, or generate it from the seed.',
            /* No unit named here: the separation is counted in hexes or through space, and the form says which. */
            self::HomeStellia => 'Gives every player a home to begin from: a single-star system, kept clear of every other.',
            self::Planets => 'Gives every star one to ten planets, ordered outward, each with its habitability and its deposits. A home system is copied from the template instead.',
            /* No seed named here: this stage draws nothing, and every player is given the same kit. */
            self::Assets => 'Settles every player on their home world: a colony on the ground, the ship that brought them in orbit above it, and what each begins holding.',
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
