<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A login a bot (connector) uses on a provider portal. Credentials are
     * encrypted at rest; `mid_ids` narrows which MIDs the account covers
     * (null = every MID where the provider is the acquirer or the gateway).
     */
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('connector', 32)->index();
            $table->string('name');
            $table->string('login_url')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('totp_secret')->nullable();
            $table->json('settings')->nullable();
            $table->json('mid_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_accounts');
    }
};
