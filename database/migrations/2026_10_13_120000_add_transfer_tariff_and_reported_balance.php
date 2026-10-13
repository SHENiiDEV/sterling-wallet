<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C2B bank transfers (LapkiPay): a percent rate per transfer on the
     * merchant (what we charge) and the provider (what it charges us), and
     * the balance the provider's cabinet showed at the last bot run.
     * NULL = not offered (N/A).
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('fee_transfer_percent', 6, 3)->nullable()->after('fee_acq_non_eu_percent');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->decimal('cost_transfer_percent', 6, 3)->nullable()->after('cost_acq_non_eu_percent');
        });

        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->decimal('reported_balance', 16, 2)->nullable();
            $table->decimal('reported_hold', 16, 2)->nullable();
            $table->timestamp('balance_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('fee_transfer_percent'));
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn('cost_transfer_percent'));
        Schema::table('merchant_mids', fn (Blueprint $table) => $table->dropColumn(['reported_balance', 'reported_hold', 'balance_reported_at']));
    }
};
