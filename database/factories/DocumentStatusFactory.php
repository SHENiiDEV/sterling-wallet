<?php

namespace Database\Factories;

use App\Models\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentStatus>
 */
class DocumentStatusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->randomElement(DocumentStatus::COLORS),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_default' => false,
            'is_final' => false,
        ];
    }

    public function asDefault(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function final(): static
    {
        return $this->state(fn (array $attributes) => ['is_final' => true]);
    }
}
