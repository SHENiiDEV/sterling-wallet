<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payer's payment reference (bank transfers: the number in the payment
     * description), which is what the merchant recognises, unlike the technical payment id.
     */
    public function up(): void
    {
        Schema::table('merchant_operations', function (Blueprint $table) {
            $table->string('reference', 128)->nullable()->after('payment_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_operations', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
