<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\MidStatus;
use App\Models\Merchant;
use App\Models\MerchantMid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantMid>
 */
class MerchantMidFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'mid' => fake()->unique()->numerify('44########'),
            'currency' => Currency::Eur,
            'status' => MidStatus::Active,
            'rolling_reserve_limit' => 100000,
        ];
    }
}
