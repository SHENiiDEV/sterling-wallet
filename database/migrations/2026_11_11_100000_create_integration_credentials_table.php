<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One secret per bot account and key: the API key of each commerce account a Corefy API bot account fetches.
     * Stored encrypted; the commerce account itself (coma_…) is not a secret.
     */
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_account_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->text('secret');
            $table->timestamps();

            $table->unique(['integration_account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
