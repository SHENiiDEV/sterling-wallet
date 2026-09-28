<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('registration_number', 64)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('billing_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
