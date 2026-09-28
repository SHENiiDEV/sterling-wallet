<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A settlement is one payout to a merchant in USDC. It can cover several
     * days and currencies: each line is a daily report, a reserve release or
     * a manual adjustment in its own currency, converted at the settlement's
     * rate for that currency (`rates`: {"EUR": "1.08", ...} = USDC per unit).
     */
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->char('payout_currency', 8)->default('USDC');
            $table->json('rates')->nullable();
            $table->decimal('total_payout', 18, 4)->default(0);
            $table->foreignId('wallet_id')->nullable()->constrained('merchant_crypto_wallets')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('tx_hash', 128)->nullable();
            $table->string('proof_path')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignId('daily_report_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reserve_ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->char('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('rate', 18, 8)->default(1);
            $table->decimal('amount_payout', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['type', 'daily_report_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
        Schema::dropIfExists('settlements');
    }
};
