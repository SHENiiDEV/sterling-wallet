<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposal PDF: the currency fixed fees are quoted in, a personal
     * introduction, and free-form extra charges ("Retrieval request" → "€5.00").
     */
    public function up(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->char('fee_currency', 4)->default('EUR')->after('fee_fiat_to_crypto_percent');
            $table->text('intro')->nullable()->after('valid_until');
            $table->json('extra_fees')->nullable()->after('settlement_terms');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->dropColumn(['fee_currency', 'intro', 'extra_fees']);
        });
    }
};
