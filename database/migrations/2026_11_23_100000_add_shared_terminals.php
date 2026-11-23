<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bank terminal for several merchants: a MID record is an assignment of the terminal to a merchant.
     * `bank_mid` is the number the bank uses in its files when it is not the record's own `mid` (which stays unique);
     * `unassigned` marks an acquirer row of a shared terminal that is not paired with a gateway payment yet: it belongs
     * to nobody, and is in no report, until the pair says whose it is.
     */
    public function up(): void
    {
        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->string('bank_mid', 64)->nullable()->index()->after('mid');
        });

        Schema::table('merchant_operations', function (Blueprint $table) {
            $table->boolean('unassigned')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_operations', function (Blueprint $table) {
            $table->dropColumn('unassigned');
        });

        Schema::table('merchant_mids', function (Blueprint $table) {
            $table->dropColumn('bank_mid');
        });
    }
};
