<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A merchant is the commercial client: one tariff set, one reserve policy,
     * one crypto provider. Card acceptance lives in merchant_mids — each MID
     * has its own currency, acquirer and gateway.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('onboarding')->index();
            $table->boolean('is_test')->default(false);
            $table->foreignId('crypto_provider_id')->nullable()->constrained('providers')->nullOnDelete();

            $table->decimal('fee_visa_eu_percent', 6, 3)->nullable();
            $table->decimal('fee_visa_non_eu_percent', 6, 3)->nullable();
            $table->decimal('fee_mastercard_eu_percent', 6, 3)->nullable();
            $table->decimal('fee_mastercard_non_eu_percent', 6, 3)->nullable();
            $table->decimal('fee_acq_eu_percent', 6, 3)->default(0);
            $table->decimal('fee_acq_non_eu_percent', 6, 3)->default(0);
            $table->decimal('fee_success_fixed', 12, 4)->default(0);
            $table->decimal('fee_decline_fixed', 12, 4)->default(0);
            $table->decimal('fee_refund_fixed', 12, 4)->default(0);
            $table->decimal('fee_chargeback_fixed', 12, 4)->default(0);
            $table->decimal('fee_fiat_to_crypto_percent', 6, 3)->default(0.4);

            $table->decimal('rolling_reserve_percent', 6, 3)->default(10);
            $table->unsignedSmallInteger('rolling_reserve_days')->default(180);

            $table->string('invoice_email')->nullable();
            $table->string('mcc', 8)->nullable();
            $table->string('onboarding_status', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
