<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('connector', 32);
            $table->foreignId('integration_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('report_date');
            $table->string('target_key', 128);
            $table->json('target')->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('files')->nullable();
            $table->unsignedInteger('rows_count')->nullable();
            $table->text('error')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->longText('log')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['connector', 'integration_account_id', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_runs');
    }
};
