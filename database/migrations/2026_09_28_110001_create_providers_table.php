<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Our cost side: what acquirers (bank), gateways (gate) and crypto
     * providers charge us. Percentages are stored as percent (1.5 = 1.5%),
     * fixed costs are charged in the currency of the MID they apply to.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('type', 16)->index();
            $table->boolean('is_active')->default(true);
            $table->string('logo_path')->nullable();

            $table->decimal('cost_visa_eu_percent', 6, 3)->nullable();
            $table->decimal('cost_visa_non_eu_percent', 6, 3)->nullable();
            $table->decimal('cost_mastercard_eu_percent', 6, 3)->nullable();
            $table->decimal('cost_mastercard_non_eu_percent', 6, 3)->nullable();
            $table->decimal('cost_acq_eu_percent', 6, 3)->default(0);
            $table->decimal('cost_acq_non_eu_percent', 6, 3)->default(0);
            $table->decimal('cost_success_fixed', 12, 4)->default(0);
            $table->decimal('cost_decline_fixed', 12, 4)->default(0);
            $table->decimal('cost_refund_fixed', 12, 4)->default(0);
            $table->decimal('cost_chargeback_fixed', 12, 4)->default(0);
            $table->decimal('cost_crypto_percent', 6, 3)->default(0);

            $table->decimal('settlement_fee', 12, 4)->default(0);
            $table->string('settlement_cycle', 32)->nullable();
            $table->decimal('min_settlement', 14, 2)->default(0);
            $table->decimal('rolling_reserve_percent', 6, 3)->default(0);
            $table->unsignedSmallInteger('rolling_reserve_days')->default(180);
            $table->decimal('rolling_reserve_cap', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
