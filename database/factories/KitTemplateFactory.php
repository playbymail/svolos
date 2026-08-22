<?php

namespace Database\Factories;

use App\Generation\KitGenerator;
use App\Generation\StartingUnits;
use App\Models\KitTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitTemplate>
 */
class KitTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The document is **drawn** rather than hand-written, so a factory-built kit is a real one that
     * satisfies every rule `Kit` enforces — a literal here would be a second, weaker copy of the
     * catalogue that could quietly stop describing every kind a game opens with.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seed = fake()->numberBetween(1, 100_000);

        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'seed' => $seed,
            'file' => null,
            'document' => (new KitGenerator(new StartingUnits))->generate($seed)->toArray(),
        ];
    }

    /**
     * A kit that was read from a document rather than drawn.
     */
    public function uploaded(string $file = 'kit.json'): static
    {
        return $this->state(fn (array $attributes): array => [
            'seed' => null,
            'file' => $file,
            'document' => [...$attributes['document'], 'seed' => null, 'file' => $file],
        ]);
    }
}
