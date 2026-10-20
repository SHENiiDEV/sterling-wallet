<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A settlement can pay a whole client: every company of the client is in scope.
     * `merchant_id` stays the merchant whose terms (settlement charge) apply.
     */
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('merchant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
