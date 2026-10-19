<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MCC the bank approved the merchant under (informational).
     */
    public function up(): void
    {
        Schema::table('merchant_acquirers', function (Blueprint $table) {
            $table->string('mcc', 4)->nullable()->after('psp');
        });

        foreach (DB::table('merchants')->whereNotNull('mcc')->get(['id', 'mcc']) as $merchant) {
            DB::table('merchant_acquirers')->where('merchant_id', $merchant->id)->update(['mcc' => $merchant->mcc]);
        }
    }

    public function down(): void
    {
        Schema::table('merchant_acquirers', function (Blueprint $table) {
            $table->dropColumn('mcc');
        });
    }
};
