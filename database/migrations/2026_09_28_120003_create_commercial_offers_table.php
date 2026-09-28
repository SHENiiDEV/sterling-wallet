<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tariff proposal to a prospect. Its fee fields mirror the merchant
     * tariff, so an accepted offer becomes a merchant with the same pricing.
     */
    public function up(): void
    {
        Schema::create('commercial_offers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('website')->nullable();
            $table->string('mcc', 8)->nullable();
            $table->json('currencies')->nullable();
            $table->decimal('expected_monthly_volume', 16, 2)->nullable();

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
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->decimal('rolling_reserve_percent', 6, 3)->default(10);
            $table->unsignedSmallInteger('rolling_reserve_days')->default(180);
            $table->string('settlement_terms')->nullable();

            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_offers');
    }
};
