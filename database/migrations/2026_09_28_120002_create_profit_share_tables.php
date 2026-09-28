<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partners get a share of monthly profit (or turnover) by rule; a rule
     * without merchant is the default for every merchant of that partner.
     * A closed monthly statement is never recalculated.
     */
    public function up(): void
    {
        Schema::create('profit_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('profit_share_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profit_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('base', 16)->default('net_profit');
            $table->decimal('percent', 7, 4);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profit_partner_id', 'merchant_id']);
        });

        Schema::create('monthly_statements', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->string('status', 16)->default('draft');
            $table->char('base_currency', 3)->default('EUR');
            $table->decimal('turnover', 18, 4)->default(0);
            $table->decimal('net_profit', 18, 4)->default(0);
            $table->decimal('shares_total', 18, 4)->default(0);
            $table->decimal('company_remainder', 18, 4)->default(0);
            $table->json('merchants')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monthly_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profit_partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_name');
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_name');
            $table->foreignId('profit_share_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('base', 16);
            $table->decimal('base_amount', 18, 4);
            $table->decimal('percent', 7, 4);
            $table->decimal('share', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_statement_lines');
        Schema::dropIfExists('monthly_statements');
        Schema::dropIfExists('profit_share_rules');
        Schema::dropIfExists('profit_partners');
    }
};
