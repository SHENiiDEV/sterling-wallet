<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff member can be restricted to one product (a C2B partner), and a
     * settlement pays one product only. Existing settlements are card, except
     * those that only pay reports of C2B terminals.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('product_scope', 16)->nullable()->after('permissions');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->string('product', 16)->default('card')->after('client_id')->index();
        });

        $c2b = fn ($settlementId, bool $c2b) => DB::table('settlement_lines')
            ->join('daily_report_tasks', 'daily_report_tasks.id', '=', 'settlement_lines.daily_report_task_id')
            ->join('merchant_mids', 'merchant_mids.id', '=', 'daily_report_tasks.merchant_mid_id')
            ->join('providers', 'providers.id', '=', 'merchant_mids.bank_provider_id')
            ->where('settlement_lines.settlement_id', $settlementId)
            ->where('settlement_lines.type', 'report')
            ->where('providers.product', $c2b ? '=' : '!=', 'c2b')
            ->exists();

        foreach (DB::table('settlements')->pluck('id') as $id) {
            if ($c2b($id, true) && ! $c2b($id, false)) {
                DB::table('settlements')->where('id', $id)->update(['product' => 'c2b']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('product');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('product_scope');
        });
    }
};
