<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rolling reserve as a ledger per MID (and so per currency):
     * holds are positive, releases/payouts negative. Balance = SUM(amount).
     */
    public function up(): void
    {
        Schema::create('reserve_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_mid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_report_task_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->string('type', 16);
            $table->decimal('amount', 18, 4);
            $table->date('release_on')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['merchant_mid_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_ledger_entries');
    }
};
