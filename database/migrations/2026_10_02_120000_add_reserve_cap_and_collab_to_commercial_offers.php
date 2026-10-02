<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offer: the rolling reserve cap (in the fee currency) and a fixed
     * "Collab" fee per operation. Both optional.
     */
    public function up(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->decimal('rolling_reserve_cap', 16, 2)->nullable()->after('rolling_reserve_days');
            $table->decimal('fee_collab_fixed', 12, 4)->nullable()->after('fee_chargeback_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->dropColumn(['rolling_reserve_cap', 'fee_collab_fixed']);
        });
    }
};
