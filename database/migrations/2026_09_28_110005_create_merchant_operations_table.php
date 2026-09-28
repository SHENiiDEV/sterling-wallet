<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = one operation from a provider report: clearing rows from the
     * MID's acquirer (role `bank`) or gateway rows (role `gate`).
     * `operation_type` is classified once on import so every screen agrees.
     * `sp_id` / `matched_operation_id` point at the same payment on the other
     * side of the MID's provider pair, as found by reconciliation.
     */
    public function up(): void
    {
        Schema::create('merchant_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_mid_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8);
            $table->string('mid', 64)->nullable()->index();
            $table->string('merchant_name')->nullable();

            $table->string('payment_id', 128)->nullable()->index();
            $table->string('sp_id', 128)->nullable()->index();
            $table->foreignId('matched_operation_id')->nullable()->constrained('merchant_operations')->nullOnDelete();
            $table->string('arn', 64)->nullable()->index();
            $table->string('rrn', 64)->nullable();
            $table->string('approval_code', 16)->nullable();
            $table->string('card_mask', 32)->nullable();
            $table->string('card_bin', 8)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('customer_email')->nullable();

            $table->string('ips', 16)->nullable();
            $table->string('region', 8)->nullable();
            $table->char('issuer_country', 2)->nullable();
            $table->string('issuer_name')->nullable();

            $table->string('trn_type', 16)->nullable();
            $table->string('operation_type', 16)->index();
            $table->string('processing_code', 64)->nullable();
            $table->string('resolution', 64)->nullable();

            $table->date('report_date')->nullable();
            $table->timestamp('transaction_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);

            $table->decimal('eu_fee', 14, 4)->nullable();
            $table->decimal('non_eu_fee', 14, 4)->nullable();
            $table->decimal('ic_fee', 14, 4)->nullable();
            $table->decimal('ic_interchange', 14, 4)->nullable();
            $table->decimal('ic_scheme_fee', 14, 4)->nullable();
            $table->decimal('approve_fee', 14, 4)->nullable();
            $table->decimal('decline_fee', 14, 4)->nullable();
            $table->decimal('refund_fee', 14, 4)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'payment_id', 'trn_type', 'amount'], 'merchant_operations_dedupe_unique');
            $table->index(['merchant_mid_id', 'report_date']);
            $table->index(['report_date', 'provider_id']);
            $table->index(['merchant_mid_id', 'role', 'matched_operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_operations');
    }
};
