<?php

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->slug(2),
            'type' => ProviderType::Bank,
            'is_active' => true,
            'cost_visa_eu_percent' => 1.5,
            'cost_visa_non_eu_percent' => 2.5,
            'cost_mastercard_eu_percent' => 1.5,
            'cost_mastercard_non_eu_percent' => 2.5,
            'cost_acq_eu_percent' => 1.5,
            'cost_acq_non_eu_percent' => 2.5,
        ];
    }

    public function gate(): static
    {
        return $this->state(fn () => ['type' => ProviderType::Gate]);
    }

    public function crypto(): static
    {
        return $this->state(fn () => ['type' => ProviderType::Crypto, 'cost_crypto_percent' => 0.25]);
    }
}
