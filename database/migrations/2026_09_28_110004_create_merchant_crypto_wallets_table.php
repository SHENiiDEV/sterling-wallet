<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_crypto_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('provider_inflow');
            $table->string('label')->nullable();
            $table->string('currency', 16)->default('USDT');
            $table->string('network', 32)->default('TRC20');
            $table->string('address');
            $table->text('seed_phrase')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_crypto_wallets');
    }
};
