<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1 unit of `base` = `rate` units of `quote`, valid from `rate_date`.
     */
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->char('base', 3);
            $table->string('quote', 8);
            $table->decimal('rate', 18, 8);
            $table->string('source', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rate_date', 'base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
