<?php

namespace App\Enums;

/**
 * What kind of thing an entity is.
 *
 * An **entity** is a unit that accepts orders, and the only kind there is — see `.ai/rules/agents.md`,
 * which committed to that before any of this existed. It accepts them from the seat that controls it
 * and from nowhere else, and it owns the units that make it up and the ones it is carrying.
 *
 * There are four, and the glossary has named all four since it was written:
 *
 * - **Open Air Colony** — on the surface of a planet, under its own sky.
 * - **Enclosed Colony** — on the surface, sealed against it.
 * - **Orbital Colony** — in orbit around a planet.
 * - **Ship** — in orbit, and the only one that can ever move.
 *
 * ## `isMobile()` says the kind may move, not that this one can
 *
 * Whether a particular ship can move depends on what it is holding — fuel, and enough engines
 * assigned to `Inventory::Components` — and nothing here answers that, because there is no
 * order yet to ask it. This is the fact about the *kind*, which is why it lives on the enum rather
 * than on the model: a colony is immobile whatever it holds, an orbital one included.
 */
enum EntityType: string
{
    case OpenAirColony = 'open_air_colony';
    case EnclosedColony = 'enclosed_colony';
    case OrbitalColony = 'orbital_colony';
    case Ship = 'ship';

    /**
     * Get the human readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAirColony => 'Open Air Colony',
            self::EnclosedColony => 'Enclosed Colony',
            self::OrbitalColony => 'Orbital Colony',
            self::Ship => 'Ship',
        };
    }

    /**
     * Determine whether entities of this kind can ever move.
     */
    public function isMobile(): bool
    {
        return match ($this) {
            self::OpenAirColony, self::EnclosedColony, self::OrbitalColony => false,
            self::Ship => true,
        };
    }

    /**
     * Determine whether a player is given entities of this kind when a game opens.
     *
     * Two of the four are. An enclosed colony and an orbital colony are things a player builds, not
     * things they are handed, which is why `StartingUnits::for()` answers them with an empty kit
     * rather than a guess and why `Kit` refuses a document that describes one.
     *
     * It lives here rather than on `StartingUnits` because it is a fact about the *kind*, the way
     * `isMobile()` is — and because two things now need it: the catalogue's own kit, and the parser
     * that decides whether an uploaded document describes a whole opening position.
     */
    public function startsAGame(): bool
    {
        return match ($this) {
            self::OpenAirColony, self::Ship => true,
            self::EnclosedColony, self::OrbitalColony => false,
        };
    }

    /**
     * Get the kinds a player is given when a game opens, in the order they are created.
     *
     * **Sweep this rather than `cases()`** whenever the question is about kits. A `cases()` sweep
     * spends half its runs on kinds that start nothing, which is how a test came to assert a value
     * against itself and be flagged as risky — see `.ai/rules/units.md`.
     *
     * @return list<self>
     */
    public static function startingKinds(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->startsAGame(),
        ));
    }

    /**
     * Get what a structural unit's assembled volume is multiplied by when it is built for this kind.
     *
     * A `Structure` unit encloses **TL²** VU in a ship or an orbital colony, **TL² × 2** in an
     * enclosed colony and **TL² × 10** in the open air — so this returns 1, 2 and 10. It is the one
     * measure in the game that depends on what a unit was assembled *for* rather than only on what
     * it is, which is why it lives here: the entity is the thing that varies, so the entity is the
     * thing that answers.
     *
     * The ordering says something about the game. The same structural unit goes furthest under an
     * open sky and least far inside a hull, because a hull has to hold pressure against vacuum and a
     * field does not. An orbital colony is a ship that cannot move, and is measured like one.
     *
     * A multiplier rather than a divisor, so that nothing here needs integer division and no measure
     * can be quietly truncated. `UnitType::assembledVolume()` is the only caller, and it applies the
     * kind's own factor on top — light structure encloses ten times what structure does.
     */
    public function structuralVolumeMultiplier(): int
    {
        return match ($this) {
            self::Ship, self::OrbitalColony => 1,
            self::EnclosedColony => 2,
            self::OpenAirColony => 10,
        };
    }
}
