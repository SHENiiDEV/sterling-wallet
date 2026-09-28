<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_mids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('mid', 64)->unique();
            $table->string('provider_login', 64)->nullable()->index();
            $table->char('currency', 3);
            $table->string('label')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->foreignId('bank_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('gate_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            // How the gateway names this MID (e.g. Corefy commerce account `coma_…`).
            $table->string('gate_mid', 64)->nullable()->index();
            $table->date('reports_start_date')->nullable();
            $table->decimal('rolling_reserve_limit', 14, 2)->default(0);
            $table->decimal('processing_limit', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_mids');
    }
};
