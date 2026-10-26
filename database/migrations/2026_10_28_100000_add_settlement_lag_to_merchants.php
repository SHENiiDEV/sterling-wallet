<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily settlement T+N: a report is paid N business days after its day
     * (the Friday-Sunday bundle counts from Friday). Empty = no schedule.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->unsignedTinyInteger('settlement_lag_days')->nullable()->after('settlement_terms');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('settlement_lag_days');
        });
    }
};
