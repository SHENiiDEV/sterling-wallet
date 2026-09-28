<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a merchant stands with each acquiring bank before and after its
     * MIDs exist: onboarding status, approved limit, integration, PSP.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('website')->nullable()->after('name');
        });

        Schema::create('merchant_acquirers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('prepare_kyb')->index();
            $table->decimal('limit', 16, 2)->nullable();
            $table->char('limit_currency', 3)->default('EUR');
            $table->string('integration_status', 24)->default('need_to_do');
            $table->string('psp')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_acquirers');
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
