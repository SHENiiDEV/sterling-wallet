<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `name` stays internal; the legal name and address are printed on the client's reports.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoice_name')->nullable()->after('name');
            $table->text('invoice_address')->nullable()->after('invoice_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['invoice_name', 'invoice_address']);
        });
    }
};
