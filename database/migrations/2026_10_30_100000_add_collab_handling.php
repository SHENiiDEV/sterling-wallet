<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_report_tasks', function (Blueprint $table) {
            $table->decimal('collabs_amount', 18, 4)->default(0)->after('chargebacks_amount');
        });

        // From this amount per transaction we dispute a Collab instead of just paying it.
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('collab_dispute_threshold', 12, 2)->nullable()->after('fee_collab_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('daily_report_tasks', fn (Blueprint $table) => $table->dropColumn('collabs_amount'));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('collab_dispute_threshold'));
    }
};
