<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'registration_number' => fake()->numerify('########'),
            'country' => fake()->randomElement(['GB', 'EE', 'CY', 'MT', 'LT']),
        ];
    }
}
