<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_acquirers', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('psp')->constrained()->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->string('status_note')->nullable()->after('status_changed_at');
        });

        // The PSP column was free text; link it to the client of the same name where there is one.
        $clients = DB::table('clients')->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id]);

        DB::table('merchant_acquirers')->orderBy('id')->each(function ($row) use ($clients) {
            DB::table('merchant_acquirers')->where('id', $row->id)->update([
                'client_id' => $row->psp !== null ? ($clients[mb_strtolower(trim($row->psp))] ?? null) : null,
                'status_changed_at' => $row->updated_at ?? $row->created_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_acquirers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['status_changed_at', 'status_note']);
        });
    }
};
