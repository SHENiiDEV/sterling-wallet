<?php

namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Models\Company;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->company(),
            'status' => MerchantStatus::Active,
            'fee_visa_eu_percent' => 3,
            'fee_visa_non_eu_percent' => 4,
            'fee_mastercard_eu_percent' => 3,
            'fee_mastercard_non_eu_percent' => 4,
            'fee_acq_eu_percent' => 3,
            'fee_acq_non_eu_percent' => 4,
            'rolling_reserve_percent' => 10,
            'invoice_email' => fake()->safeEmail(),
        ];
    }
}
