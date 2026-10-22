<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The successful volume a terminal is expected to take every day, in the
     * MID currency. Without it the monthly processing limit spread over the month is used.
     */
    public function up(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->decimal('daily_norm', 14, 2)->nullable()->after('processing_limit');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->dropColumn('daily_norm');
        });
    }
};
