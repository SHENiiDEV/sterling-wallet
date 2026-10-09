<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appendix 1 terms: Apple Pay / Google Pay surcharge, settlement FX
     * markup, per-settlement charge, minimum settlement and a merchant-wide
     * reserve cap — on the merchant (what we charge) and the provider
     * (what it charges us).
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('fee_wallet_percent', 6, 3)->default(0)->after('fee_collab_fixed');
            $table->decimal('fee_settlement_fx_percent', 6, 3)->default(0)->after('fee_fiat_to_crypto_percent');
            $table->decimal('fee_settlement_fixed', 12, 4)->default(0)->after('fee_settlement_fx_percent');
            $table->decimal('min_settlement_amount', 14, 2)->nullable()->after('fee_settlement_fixed');
            $table->decimal('rolling_reserve_cap', 16, 2)->nullable()->after('rolling_reserve_days');
            $table->string('settlement_terms')->nullable()->after('rolling_reserve_cap');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->decimal('cost_wallet_percent', 6, 3)->default(0)->after('cost_acq_non_eu_percent');
            $table->decimal('cost_settlement_fx_percent', 6, 3)->default(0)->after('cost_crypto_percent');
        });

        Schema::table('merchant_operations', function (Blueprint $table) {
            // apple_pay / google_pay when the provider file says so.
            $table->string('wallet', 16)->nullable()->after('ips');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['fee_wallet_percent', 'fee_settlement_fx_percent', 'fee_settlement_fixed', 'min_settlement_amount', 'rolling_reserve_cap', 'settlement_terms']);
        });
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['cost_wallet_percent', 'cost_settlement_fx_percent']);
        });
        Schema::table('merchant_operations', function (Blueprint $table) {
            $table->dropColumn('wallet');
        });
    }
};
