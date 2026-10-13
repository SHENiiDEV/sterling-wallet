<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One tariff per product. Card acquiring stays on the merchant; the
     * other products (C2B, later Open Banking) get a row each: processing
     * percent, success / refund fee, conversion and FX markup.
     * Providers now say which product they serve.
     */
    public function up(): void
    {
        Schema::create('merchant_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('product', 16);
            $table->decimal('transfer_percent', 6, 3)->nullable();
            $table->decimal('success_fixed', 12, 4)->default(0);
            $table->decimal('refund_fixed', 12, 4)->default(0);
            $table->decimal('fiat_to_crypto_percent', 6, 3)->default(0);
            $table->decimal('settlement_fx_percent', 6, 3)->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'product']);
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->string('product', 16)->default('card')->after('type');
        });
        DB::table('providers')->where('report_format', 'lapkipay')->update(['product' => 'c2b']);

        // The transfer rate typed on the merchant moves into its C2B tariff.
        $now = now();
        foreach (DB::table('merchants')->whereNotNull('fee_transfer_percent')->get() as $merchant) {
            DB::table('merchant_tariffs')->insert([
                'merchant_id' => $merchant->id,
                'product' => 'c2b',
                'transfer_percent' => $merchant->fee_transfer_percent,
                'success_fixed' => $merchant->fee_success_fixed,
                'refund_fixed' => $merchant->fee_refund_fixed,
                'fiat_to_crypto_percent' => $merchant->fee_fiat_to_crypto_percent,
                'settlement_fx_percent' => $merchant->fee_settlement_fx_percent,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('fee_transfer_percent'));
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('fee_transfer_percent', 6, 3)->nullable()->after('fee_acq_non_eu_percent');
        });
        foreach (DB::table('merchant_tariffs')->where('product', 'c2b')->get() as $tariff) {
            DB::table('merchants')->where('id', $tariff->merchant_id)->update(['fee_transfer_percent' => $tariff->transfer_percent]);
        }

        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn('product'));
        Schema::dropIfExists('merchant_tariffs');
    }
};
