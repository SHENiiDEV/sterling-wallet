<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Authenticator (TOTP) secrets of the platforms the team logs into: the secret is stored encrypted.
        Schema::create('authenticator_codes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service')->nullable();
            $table->text('secret');
            $table->unsignedTinyInteger('digits')->default(6);
            $table->unsignedSmallInteger('period')->default(30);
            $table->string('algorithm', 8)->default('sha1');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Where a code is shown: on the page of the merchant, company, client or provider it is linked to.
        Schema::create('authenticator_code_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authenticator_code_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['authenticator_code_id', 'target_type', 'target_id'], 'authenticator_links_unique');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authenticator_code_links');
        Schema::dropIfExists('authenticator_codes');
    }
};
