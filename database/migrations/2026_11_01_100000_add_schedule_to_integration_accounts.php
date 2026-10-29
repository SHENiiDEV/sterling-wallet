<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the scheduler may start this account: its bot's own rhythm (auto), every N minutes, or once a day at a time.
        Schema::table('integration_accounts', function (Blueprint $table) {
            $table->string('schedule_mode', 16)->default('auto')->after('is_active');
            $table->unsignedSmallInteger('schedule_every_minutes')->nullable()->after('schedule_mode');
            $table->string('schedule_at', 5)->nullable()->after('schedule_every_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('integration_accounts', fn (Blueprint $table) => $table->dropColumn(['schedule_mode', 'schedule_every_minutes', 'schedule_at']));
    }
};
