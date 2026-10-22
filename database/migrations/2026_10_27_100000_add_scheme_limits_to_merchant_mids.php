<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some banks give one terminal a separate monthly limit per card scheme
     * (e.g. 200k on Visa and 200k on Mastercard). When set they replace the single processing limit.
     */
    public function up(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->decimal('processing_limit_visa', 14, 2)->nullable()->after('processing_limit');
            $table->decimal('processing_limit_mastercard', 14, 2)->nullable()->after('processing_limit_visa');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->dropColumn(['processing_limit_visa', 'processing_limit_mastercard']);
        });
    }
};
