<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reserve agreed for the whole client, over all its companies: while `reserve_pool_enabled` is on, the MID limits
     * and the merchant caps are ignored and every report holds its percent until the client's balance reaches
     * `reserve_pool_limit` (in the currency of each MID: the balance is counted per currency).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('reserve_pool_enabled')->default(false);
            $table->decimal('reserve_pool_limit', 16, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['reserve_pool_enabled', 'reserve_pool_limit']);
        });
    }
};
