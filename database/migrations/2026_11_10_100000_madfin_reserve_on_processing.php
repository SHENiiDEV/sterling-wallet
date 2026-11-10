<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Madfin takes the rolling reserve from the processing amount before any fee. From this report date on our reports
     * hold it the same way; reports before it stay as they were paid.
     */
    public function up(): void
    {
        $madfin = DB::table('providers')->where('code', 'madfin')->first();
        if ($madfin === null) {
            return;
        }

        $matching = json_decode((string) ($madfin->matching ?? ''), true) ?: [];
        $matching['reserve_base'] = 'processing';
        $matching['reserve_base_from'] = '2026-11-10';

        DB::table('providers')->where('id', $madfin->id)->update(['matching' => json_encode($matching)]);
    }

    public function down(): void
    {
        $madfin = DB::table('providers')->where('code', 'madfin')->first();
        if ($madfin === null) {
            return;
        }

        $matching = json_decode((string) ($madfin->matching ?? ''), true) ?: [];
        unset($matching['reserve_base'], $matching['reserve_base_from']);

        DB::table('providers')->where('id', $madfin->id)->update(['matching' => $matching === [] ? null : json_encode($matching)]);
    }
};
