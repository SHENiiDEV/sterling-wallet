<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currency the contract names its fixed amounts in (per-transaction fees, refund and chargeback fees, the reserve
     * cap), when it is not the currency of the MID: a EUR contract on a GBP terminal. Empty = the MID's own currency.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('fee_currency', 3)->nullable()->after('mcc');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('fee_currency');
        });
    }
};
