<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            // Further gateway accounts (e.g. Corefy commerce accounts) that process under this same MID.
            $table->json('extra_gate_mids')->nullable()->after('gate_mid');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->dropColumn('extra_gate_mids');
        });
    }
};
