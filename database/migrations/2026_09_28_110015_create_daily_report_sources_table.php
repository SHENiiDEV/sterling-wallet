<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which provider files a daily report has received. A report is complete
     * when every provider of its MID (acquirer, and gateway if set) is here.
     */
    public function up(): void
    {
        Schema::create('daily_report_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8);
            $table->string('file_path')->nullable();
            $table->timestamp('received_at');
            $table->unsignedInteger('rows_count')->default(0);
            $table->foreignId('bot_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['daily_report_task_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_sources');
    }
};
