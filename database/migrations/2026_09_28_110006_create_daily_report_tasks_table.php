<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One daily report per MID. Amounts are in the MID currency; *_base columns
     * hold the same figures converted to the reporting currency at `fx_rate`,
     * frozen when the report was generated.
     */
    public function up(): void
    {
        Schema::create('daily_report_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_mid_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending')->index();

            $table->boolean('is_cardaq_received')->default(false);
            $table->boolean('is_corefy_received')->default(false);
            $table->string('cardaq_file_path')->nullable();
            $table->string('corefy_file_path')->nullable();
            $table->string('generated_xlsx_path')->nullable();
            $table->string('generated_pdf_path')->nullable();
            $table->string('generated_operations_path')->nullable();
            $table->text('error_log')->nullable();

            $table->unsignedInteger('sales_count')->default(0);
            $table->decimal('turnover', 18, 4)->default(0);
            $table->decimal('refunds_amount', 18, 4)->default(0);
            $table->decimal('chargebacks_amount', 18, 4)->default(0);
            $table->decimal('total_merchant_fee', 18, 4)->default(0);
            $table->decimal('total_provider_cost', 18, 4)->default(0);
            $table->decimal('reserve_amount', 18, 4)->default(0);
            $table->decimal('net_volume', 18, 4)->default(0);
            $table->decimal('conversion_fee', 18, 4)->default(0);
            $table->decimal('net_payout', 18, 4)->default(0);
            $table->decimal('net_profit', 18, 4)->default(0);

            $table->char('base_currency', 3)->default('EUR');
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->decimal('turnover_base', 18, 4)->nullable();
            $table->decimal('net_profit_base', 18, 4)->nullable();
            $table->json('summary_data')->nullable();

            $table->boolean('is_email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_mid_id', 'report_date']);
            $table->index(['report_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_tasks');
    }
};
