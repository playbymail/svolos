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
     * Get what a structural unit's assembled volume is divided by when it is built for this kind.
     *
     * Structure assembled for a ship encloses **TL² / 10** VU, for an enclosed colony **TL² / 5**,
     * and for an open air colony **TL²** — so this returns 10, 5 and 1. It is the one measure in the
     * game that depends on what a unit was assembled *for* rather than only on what it is, which is
     * why it lives here: the entity is the thing that varies, so the entity is the thing that
     * answers.
     *
     * The ordering says something about the game. The same structural unit goes furthest under an
     * open sky and least far inside a hull, because a hull has to hold pressure against vacuum and a
     * field does not. An orbital colony is a ship that cannot move, and is measured like one.
     *
     * `UnitType::assembledVolume()` is the only caller.
     */
    public function structuralVolumeDivisor(): int
    {
        return match ($this) {
            self::Ship, self::OrbitalColony => 10,
            self::EnclosedColony => 5,
            self::OpenAirColony => 1,
        };
    }
}
