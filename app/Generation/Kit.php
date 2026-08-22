<?php

namespace App\Generation;

use App\Enums\EntityType;
use App\Enums\Inventory;
use App\Enums\UnitType;
use InvalidArgumentException;

/**
 * What every player in one game begins with.
 *
 * A colony's worth of holdings and a ship's worth, settled once for a game and handed to every player
 * in it unchanged. `StartingUnits` is the catalogue's own kit written as constants; this is the same
 * thing as data, so that a gamemaster can draw one, edit one, or upload one.
 *
 * Pure, like the generators beside it: no models, no clock, no container. It reaches the database as
 * a json column on the run that settled it (`generation_runs.kit`), through `toArray()`, and as
 * `kit_templates.document` in a gamemaster's library.
 *
 * ## The fairness rule is about players, not about games
 *
 * `StartingUnits` says the units stage draws nothing, because "the alternative is that the seed
 * decides who begins ahead". That argument is about **per-player** variation and it is untouched
 * here: a kit is settled once per game and every player in that game gets it down to the last tonne.
 * What a seed now varies is what *this game's* opening is, which is exactly what
 * `HomeTemplateGenerator` does for the home system every player shares.
 *
 * So the rule to hold is: **one kit per game, identical for every player in it.** Anything that gives
 * two players in one game different holdings breaks the thing this was built around.
 *
 * ## Parsing is validation, and every refusal names the field
 *
 * `fromJson()` is the uploaded half; `KitGenerator` is the drawn half. Everything this can refuse
 * throws `GenerationFailed` carrying `$field = 'kit'`, which `Gamemaster\GenerationController`
 * already turns into a validation message on that field — so a gamemaster who hand-writes a document
 * with a typo is told which entity and which holding, on the form, rather than shown an error page.
 *
 * **A document must describe every kind that starts a game.** A missing ship is refused rather than
 * read as "the ship starts empty": a kit is the whole opening position, and silently launching
 * everybody with no vessel is not something anybody meant to ask for. `EntityType::startsAGame()` is
 * the list, and a kind that starts nothing is refused if a document names one.
 *
 * ## The seed rides inside the document
 *
 * This is the one place it departs from `HomeTemplate`, which carries only `file`. A kit is meant to
 * round-trip — draw one, download it, edit it, upload it back — and the number it came from is part
 * of what it is. Nothing ever redraws from it: by the time a document exists the quantities are
 * settled, so the seed is provenance, and null means somebody wrote this rather than drew it.
 */
final readonly class Kit
{
    /**
     * The widest seed a kit may record, mirroring `Game::SEED_MIN` and `Game::SEED_MAX`.
     *
     * Restated rather than imported for the reason `HomeTemplate::MAXIMUM_VALUE` restates the planets
     * table's `unsignedTinyInteger`: nothing in `app/Generation` may reach for a model, and the honest
     * bound on a stored number is what its column can hold — `kit_templates.seed` is an
     * `unsignedInteger`. `KitTest` asserts the pair still agrees with `Game`'s, so the day one moves
     * it is a failing test rather than a truncated number.
     */
    public const int MINIMUM_SEED = 0;

    public const int MAXIMUM_SEED = 4294967295;

    /**
     * @param  list<KitEntity>  $entities
     * @param  int|null  $seed  the seed this was drawn from, or null when it was written by hand
     * @param  string|null  $file  the document this was read from, or null when it was generated
     */
    public function __construct(
        public array $entities,
        public ?int $seed = null,
        public ?string $file = null,
    ) {
        $this->guardEveryKindIsDescribedOnce();
    }

    /**
     * Read a kit from an uploaded document.
     *
     * @throws GenerationFailed if the document is not a kit a game could open with
     */
    public static function fromJson(string $json, string $file): self
    {
        $document = json_decode($json, true);

        if (! is_array($document)) {
            throw GenerationFailed::kitUnreadable(json_last_error_msg());
        }

        return self::fromDocument($document, $file);
    }

    /**
     * Read a kit from a document that has already been decoded.
     *
     * The strict half, and the seam **two** callers share: `fromJson()` after decoding an uploaded
     * file, and `Gamemaster\KitTemplateController` after a gamemaster edits a kit in the browser.
     * Both are somebody's posted data, so both need every refusal, and having one of them reach for
     * `fromArray()` instead would be the quiet way to write an unusable kit into the library.
     *
     * `$file` is nullable here where `fromJson()` demands one, because an edited kit was not read
     * from a document — the caller passes through whatever the row already remembered.
     *
     * @param  array<mixed>  $document
     *
     * @throws GenerationFailed if the document is not a kit a game could open with
     */
    public static function fromDocument(array $document, ?string $file = null): self
    {
        $entities = $document['entities'] ?? null;

        if (! is_array($entities) || ! array_is_list($entities) || $entities === []) {
            throw GenerationFailed::kitMalformed('The document needs an "entities" list.');
        }

        $read = [];

        foreach ($entities as $index => $entity) {
            $read[] = self::readEntity($entity, $index + 1);
        }

        try {
            return new self($read, self::readSeed($document), $file);
        } catch (InvalidArgumentException $refusal) {
            throw GenerationFailed::kitMalformed($refusal->getMessage());
        }
    }

    /**
     * Read a kit back from the run, or the library row, that stored it.
     *
     * Unlike `fromJson()` this trusts what it reads: every stored document was written by `toArray()`
     * on a kit that had already been validated, so a refusal here would be a bug in this class rather
     * than a message for anybody.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $entities = array_map(
            fn (array $entity): KitEntity => new KitEntity(
                EntityType::from($entity['type']),
                array_values(array_map(
                    fn (array $holding): UnitHolding => new UnitHolding(
                        UnitType::from($holding['type']),
                        Inventory::from($holding['inventory']),
                        $holding['quantity'],
                        $holding['technology_level'] ?? UnitType::NO_TECHNOLOGY_LEVEL,
                    ),
                    $entity['holdings'],
                )),
            ),
            $stored['entities'],
        );

        return new self(array_values($entities), $stored['seed'] ?? null, $stored['file'] ?? null);
    }

    /**
     * Shape this for the json column, and for the document a gamemaster downloads.
     *
     * One shape for both on purpose: what comes out of the download is exactly what goes back in
     * through the upload, so a round trip cannot lose anything. `seed` and `file` are written even
     * when null, because a document a person edits should show them the keys it accepts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'file' => $this->file,
            'entities' => array_map(
                fn (KitEntity $entity): array => $entity->toArray(),
                $this->entities,
            ),
        ];
    }

    /**
     * Get the kit for one kind of entity.
     *
     * The seam `GenerateUnits` writes through, and the same signature `StartingUnits::for()` has, so
     * the action reads one or the other without caring which. A kind this kit says nothing about
     * answers with an empty list rather than a guess.
     *
     * @return list<UnitHolding>
     */
    public function for(EntityType $type): array
    {
        foreach ($this->entities as $entity) {
            if ($entity->type === $type) {
                return $entity->holdings;
            }
        }

        return [];
    }

    /**
     * Get how many holdings the whole kit describes.
     */
    public function holdingCount(): int
    {
        return array_sum(array_map(
            fn (KitEntity $entity): int => count($entity->holdings),
            $this->entities,
        ));
    }

    /**
     * Describe the kit, for the card that reviews it and the screen that lists it.
     *
     * `file` and `seed` are both null on a hand-written kit that was never downloaded, and
     * `GenerationStageCard` drops null values rather than printing the word "null" — so a summary
     * says which of the three ways this kit arrived without anything having to branch.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'file' => $this->file,
            'seed' => $this->seed,
            'entities' => count($this->entities),
            'holdings' => $this->holdingCount(),
        ];
    }

    /**
     * Read the optional seed off an uploaded document.
     *
     * @param  array<mixed>  $document
     *
     * @throws GenerationFailed
     */
    private static function readSeed(array $document): ?int
    {
        $seed = $document['seed'] ?? null;

        if ($seed === null) {
            return null;
        }

        if (! is_int($seed) || $seed < self::MINIMUM_SEED || $seed > self::MAXIMUM_SEED) {
            throw GenerationFailed::kitMalformed(
                'The seed needs to be a whole number between '.self::MINIMUM_SEED.' and '
                .self::MAXIMUM_SEED.', or left out altogether.'
            );
        }

        return $seed;
    }

    /**
     * Read one entity of an uploaded document.
     *
     *
     * @throws GenerationFailed
     */
    private static function readEntity(mixed $entity, int $position): KitEntity
    {
        if (! is_array($entity)) {
            throw GenerationFailed::kitMalformed("Entry {$position} of the kit is not an object.");
        }

        $type = EntityType::tryFrom(is_string($entity['type'] ?? null) ? $entity['type'] : '');

        if ($type === null) {
            throw GenerationFailed::kitMalformed(
                "Entry {$position} of the kit has no usable type. Use one of: "
                .implode(', ', array_map(
                    fn (EntityType $kind): string => $kind->value,
                    EntityType::startingKinds(),
                )).'.'
            );
        }

        /*
         * Refused rather than accepted and ignored: an enclosed colony and an orbital colony are
         * things a player builds, so a document describing one is claiming something the stage would
         * never read, and dropping it quietly would leave somebody believing they had set it.
         */
        if (! $type->startsAGame()) {
            throw GenerationFailed::kitMalformed(
                "{$type->label()} is something a player builds rather than begins with, so a kit "
                .'cannot describe one.'
            );
        }

        $holdings = $entity['holdings'] ?? null;

        if (! is_array($holdings) || ! array_is_list($holdings) || $holdings === []) {
            throw GenerationFailed::kitMalformed(
                "The {$type->label()} needs a \"holdings\" list with at least one entry in it."
            );
        }

        $read = [];

        foreach ($holdings as $index => $holding) {
            $read[] = self::readHolding($holding, $index + 1, $type);
        }

        try {
            return new KitEntity($type, $read);
        } catch (InvalidArgumentException $refusal) {
            throw GenerationFailed::kitMalformed($refusal->getMessage());
        }
    }

    /**
     * Read one holding of an uploaded document.
     *
     * The catalogue's own rules — which inventories a kind may sit in, which kinds take a technology
     * level, that a quantity is at least one — are enforced by `UnitHolding`'s constructor, which
     * throws `InvalidArgumentException` because its docblock says nothing a gamemaster posts reaches
     * it. An uploaded document is exactly that case, so the refusal is caught here and rethrown as a
     * failure that lands on the form, prefixed with the entity it belongs to.
     *
     *
     * @throws GenerationFailed
     */
    private static function readHolding(mixed $holding, int $position, EntityType $entity): UnitHolding
    {
        $where = "Holding {$position} of the {$entity->label()}";

        if (! is_array($holding)) {
            throw GenerationFailed::kitMalformed("{$where} is not an object.");
        }

        $type = UnitType::tryFrom(is_string($holding['type'] ?? null) ? $holding['type'] : '');

        if ($type === null) {
            throw GenerationFailed::kitMalformed(
                "{$where} has no usable type. Use one of: "
                .implode(', ', array_column(UnitType::cases(), 'value')).'.'
            );
        }

        $inventory = Inventory::tryFrom(is_string($holding['inventory'] ?? null) ? $holding['inventory'] : '');

        if ($inventory === null) {
            throw GenerationFailed::kitMalformed(
                "{$where} has no usable inventory. Use one of: "
                .implode(', ', array_column(Inventory::cases(), 'value')).'.'
            );
        }

        $quantity = $holding['quantity'] ?? null;

        if (! is_int($quantity)) {
            throw GenerationFailed::kitMalformed("{$where} needs a whole quantity.");
        }

        $technologyLevel = $holding['technology_level'] ?? UnitType::NO_TECHNOLOGY_LEVEL;

        if (! is_int($technologyLevel)) {
            throw GenerationFailed::kitMalformed("{$where} needs a whole technology level.");
        }

        try {
            return new UnitHolding($type, $inventory, $quantity, $technologyLevel);
        } catch (InvalidArgumentException $refusal) {
            throw GenerationFailed::kitMalformed("{$where}: {$refusal->getMessage()}");
        }
    }

    /**
     * Refuse a kit that repeats a kind of entity, or leaves one of them out.
     *
     * The sweep is `EntityType::startingKinds()` rather than `cases()`, because two of the four kinds
     * start nothing — see `.ai/rules/units.md`, where a `cases()` sweep is the mistake that made a
     * test assert a value against itself.
     *
     * @throws InvalidArgumentException
     */
    private function guardEveryKindIsDescribedOnce(): void
    {
        $described = array_map(fn (KitEntity $entity): EntityType => $entity->type, $this->entities);

        foreach ($described as $position => $type) {
            if (in_array($type, array_slice($described, 0, $position), true)) {
                throw new InvalidArgumentException(
                    sprintf('This kit describes the %s twice. Describe each one once.', $type->label())
                );
            }
        }

        foreach (EntityType::startingKinds() as $required) {
            if (! in_array($required, $described, true)) {
                throw new InvalidArgumentException(
                    sprintf('This kit says nothing about the %s.', $required->label())
                );
            }
        }
    }
}
