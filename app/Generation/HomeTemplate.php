<?php

namespace App\Generation;

use App\Enums\PlanetType;

/**
 * The home system every player begins in.
 *
 * A template is what makes the start of a game fair: every player's home holds the same planets in
 * the same order, at the same habitability, and their home world is identical down to its deposits.
 * What differs is only what the *neighbours* are worth to mine, which `PlanetGenerator` draws for
 * each home separately — see `HomeTemplatePlanet` for why that lives in the nulls.
 *
 * Pure, like the generators beside it: no models, no clock, no container. It reaches the database as
 * a json column on the run that settled it (`generation_runs.template`), through `toArray()`.
 *
 * ## Parsing is validation, and every refusal names the field
 *
 * `fromJson()` is the uploaded half of the stage — the other half is `HomeTemplateGenerator`, which
 * draws one instead. Everything it can refuse throws `GenerationFailed` carrying `$field =
 * 'template'`, which `Gamemaster\GenerationController` already turns into a validation message on
 * that field. So a gamemaster who uploads a document with a typo in it is told which planet and what
 * is wrong with it, on the form, rather than being shown an error page.
 *
 * The refusals are deliberately strict in one direction that might read as unhelpful: **deposits on
 * a planet that is not the home world are rejected rather than ignored.** They would be drawn per
 * player, so a document carrying them is claiming control it does not have, and silently dropping
 * them would leave a gamemaster believing they had set something.
 *
 * ## The bounds are the column's, not the generator's
 *
 * A planet's four values are checked against 0–255, the `unsignedTinyInteger` the `planets` table
 * declares — not against the 25 and 35 the dice tables happen to reach. A drawn planet is the outcome
 * of a distribution and has to stay inside it; a template is somebody's deliberate choice, and the
 * honest limit on a deliberate choice is what the column can hold.
 */
final readonly class HomeTemplate
{
    /**
     * The widest a template may be, taken from the dice rather than restated.
     *
     * A home has to be a system the cluster could plausibly have contained, so it is held to the same
     * one-to-ten `PlanetGenerator::PLANET_DICE` produces. Deriving the pair from that constant means a
     * change to the dice moves this with it instead of leaving two numbers to notice.
     */
    public const int MINIMUM_PLANETS = PlanetGenerator::PLANET_DICE[0] + PlanetGenerator::PLANET_DICE[2];

    public const int MAXIMUM_PLANETS = PlanetGenerator::PLANET_DICE[0] * PlanetGenerator::PLANET_DICE[1] + PlanetGenerator::PLANET_DICE[2];

    /**
     * The largest value any of a planet's four columns can hold.
     *
     * `unsignedTinyInteger`, asserted against the schema by `PlanetGeneratorTest` for the drawn side.
     */
    public const int MAXIMUM_VALUE = 255;

    /**
     * @param  list<HomeTemplatePlanet>  $planets
     * @param  string|null  $file  the document this was read from, or null when it was generated
     */
    public function __construct(
        public array $planets,
        public ?string $file = null,
    ) {}

    /**
     * Read a template from an uploaded document.
     *
     * @throws GenerationFailed if the document is not a template this game could use
     */
    public static function fromJson(string $json, string $file): self
    {
        $document = json_decode($json, true);

        if (! is_array($document)) {
            throw GenerationFailed::templateUnreadable(json_last_error_msg());
        }

        $planets = $document['planets'] ?? null;

        if (! is_array($planets) || ! array_is_list($planets)) {
            throw GenerationFailed::templateMalformed('The document needs a "planets" list.');
        }

        $count = count($planets);

        if ($count < self::MINIMUM_PLANETS || $count > self::MAXIMUM_PLANETS) {
            throw GenerationFailed::templateMalformed(
                'A home system has between '.self::MINIMUM_PLANETS.' and '.self::MAXIMUM_PLANETS
                ." planets, and this one has {$count}."
            );
        }

        $read = [];
        $homes = [];

        foreach ($planets as $index => $planet) {
            $ordinal = $index + 1;

            if (! is_array($planet)) {
                throw GenerationFailed::templateMalformed("Planet {$ordinal} is not an object.");
            }

            /*
             * The ordinal is checked rather than trusted, and the list's own order is what is kept.
             * A document numbering its planets 1, 2, 4 is a typo somebody wants to hear about — the
             * alternative is quietly generating a home system that is not the one they described.
             */
            if (($planet['ordinal'] ?? $ordinal) !== $ordinal) {
                throw GenerationFailed::templateMalformed(
                    "Planet {$ordinal} is numbered {$planet['ordinal']}. Number the planets 1 to {$count}, in order."
                );
            }

            $read[] = self::readPlanet($planet, $ordinal);

            if ($planet['home'] ?? false) {
                $homes[] = $ordinal;
            }
        }

        self::guardOneHome($homes);

        return new self($read, $file);
    }

    /**
     * Read a template back from the run that stored it.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $planets = array_map(
            fn (array $planet): HomeTemplatePlanet => isset($planet['fuel'])
                ? HomeTemplatePlanet::home(
                    PlanetType::from($planet['type']),
                    $planet['habitability'],
                    $planet['fuel'],
                    $planet['metals'],
                    $planet['minerals'],
                )
                : HomeTemplatePlanet::drawn(PlanetType::from($planet['type']), $planet['habitability']),
            $stored['planets'],
        );

        return new self(array_values($planets), $stored['file'] ?? null);
    }

    /**
     * Shape this for the json column on the run.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'home_ordinal' => $this->homeOrdinal(),
            'planets' => array_map(
                fn (HomeTemplatePlanet $planet): array => array_filter([
                    'type' => $planet->type->value,
                    'habitability' => $planet->habitability,
                    'fuel' => $planet->fuel,
                    'metals' => $planet->metals,
                    'minerals' => $planet->minerals,
                ], fn (mixed $value): bool => $value !== null),
                $this->planets,
            ),
        ];
    }

    /**
     * Get where the home world sits in the system, counting from one.
     */
    public function homeOrdinal(): int
    {
        foreach ($this->planets as $index => $planet) {
            if ($planet->isHome()) {
                return $index + 1;
            }
        }

        /*
         * Unreachable: both ways of building a template settle a home world, and `fromArray()` only
         * ever reads one this class wrote. Throwing rather than returning a plausible 1 keeps a
         * future third constructor from producing a template nobody can begin in.
         */
        throw GenerationFailed::templateMalformed('This template names no home world.');
    }

    /**
     * Get the world the players actually begin on.
     */
    public function home(): HomeTemplatePlanet
    {
        return $this->planets[$this->homeOrdinal() - 1];
    }

    /**
     * Describe the template, for the card that reviews it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $home = $this->home();

        return [
            /* Null for a generated template, which is how the screen says which of the two this was. */
            'file' => $this->file,
            'planets' => count($this->planets),
            'home_ordinal' => $this->homeOrdinal(),
            'home_habitability' => $home->habitability,
            /*
             * `types`, the key `PlanetPlan::summary()` uses, and **not** `mix`. The card special-cases
             * `mix` by appending "star" to each key, because a stellium mix is keyed by how many stars
             * a stellium holds and "1 342" would say nothing. Keyed by planet type, that same treatment
             * renders "rocky stars 3" — which is wrong twice over, since these are planets and there is
             * one star.
             */
            'types' => array_count_values(array_map(
                fn (HomeTemplatePlanet $planet): string => $planet->type->value,
                $this->planets,
            )),
        ];
    }

    /**
     * Read one planet of an uploaded document.
     *
     * @param  array<mixed>  $planet
     *
     * @throws GenerationFailed
     */
    private static function readPlanet(array $planet, int $ordinal): HomeTemplatePlanet
    {
        $type = PlanetType::tryFrom(is_string($planet['type'] ?? null) ? $planet['type'] : '');

        if ($type === null) {
            throw GenerationFailed::templateMalformed(
                "Planet {$ordinal} has no usable type. Use one of: "
                .implode(', ', array_column(PlanetType::cases(), 'value')).'.'
            );
        }

        $habitability = self::readValue($planet, 'habitability', $ordinal);

        if (! ($planet['home'] ?? false)) {
            /*
             * Rejected rather than ignored: these are drawn for every player separately, so a
             * document setting them is describing something it does not control.
             */
            foreach (['fuel', 'metals', 'minerals'] as $deposit) {
                if (array_key_exists($deposit, $planet)) {
                    throw GenerationFailed::templateMalformed(
                        "Planet {$ordinal} sets its {$deposit}, but only the home world's deposits are "
                        .'fixed by a template — every other planet is drawn for each player.'
                    );
                }
            }

            return HomeTemplatePlanet::drawn($type, $habitability);
        }

        return HomeTemplatePlanet::home(
            $type,
            $habitability,
            self::readValue($planet, 'fuel', $ordinal),
            self::readValue($planet, 'metals', $ordinal),
            self::readValue($planet, 'minerals', $ordinal),
        );
    }

    /**
     * Read one of a planet's four numbers.
     *
     * @param  array<mixed>  $planet
     *
     * @throws GenerationFailed
     */
    private static function readValue(array $planet, string $key, int $ordinal): int
    {
        $value = $planet[$key] ?? null;

        if (! is_int($value) || $value < 0 || $value > self::MAXIMUM_VALUE) {
            throw GenerationFailed::templateMalformed(
                "Planet {$ordinal} needs a whole {$key} between 0 and ".self::MAXIMUM_VALUE.'.'
            );
        }

        return $value;
    }

    /**
     * Refuse a document that names anything but exactly one home world.
     *
     * @param  list<int>  $homes
     *
     * @throws GenerationFailed
     */
    private static function guardOneHome(array $homes): void
    {
        if ($homes === []) {
            throw GenerationFailed::templateMalformed(
                'No planet is marked as the home world. Add "home": true to the one the players begin on.'
            );
        }

        if (count($homes) > 1) {
            throw GenerationFailed::templateMalformed(
                'Planets '.implode(' and ', $homes).' are both marked as the home world. Mark exactly one.'
            );
        }
    }
}
