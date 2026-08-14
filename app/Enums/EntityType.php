<?php

namespace App\Enums;

/**
 * What kind of thing an entity is.
 *
 * An **entity** is a unit that accepts orders, and the only kind there is — see `.ai/rules/agents.md`,
 * which committed to that before any of this existed. It accepts them from the seat that controls it
 * and from nowhere else, and it owns the assets that make it up and the ones it is carrying.
 *
 * There are two kinds and the difference between them is movement:
 *
 * - **Colony** — assembled at a location and never moved from it. It can work mines and factories.
 * - **Ship** — mobile. With fuel and enough engines it can move between the planets of a stellium, or
 *   jump to another stellium.
 *
 * ## `isMobile()` says the kind may move, not that this one can
 *
 * Whether a particular ship can move depends on what it is holding — fuel, and enough engines
 * assigned to `AssetAssignment::Infrastructure` — and nothing here answers that, because there is no
 * order yet to ask it. This is the fact about the *kind*, which is why it lives on the enum rather
 * than on the model: a colony is immobile whatever it holds.
 */
enum EntityType: string
{
    case Colony = 'colony';
    case Ship = 'ship';

    /**
     * Get the human readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Colony => 'Colony',
            self::Ship => 'Ship',
        };
    }

    /**
     * Determine whether entities of this kind can ever move.
     */
    public function isMobile(): bool
    {
        return match ($this) {
            self::Colony => false,
            self::Ship => true,
        };
    }
}
