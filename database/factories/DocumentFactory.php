<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'type' => fake()->randomElement(DocumentType::cases()),
            'counterparty' => fake()->company(),
            'document_status_id' => DocumentStatus::factory(),
            'due_date' => fake()->optional()->dateTimeBetween('-1 week', '+1 month'),
            'notes' => null,
            'status_changed_at' => now(),
        ];
    }
}
