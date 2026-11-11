<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Madfin's statement for 4–8 November holds the rolling reserve at 10% of the approved sales of each day, before
     * any fee and with refunds not netted. The switch starts with the report of 4 November, not the 10th.
     */
    public function up(): void
    {
        $madfin = DB::table('providers')->where('code', 'madfin')->first();
        if ($madfin === null) {
            return;
        }

        $matching = json_decode((string) ($madfin->matching ?? ''), true) ?: [];
        $matching['reserve_base'] = 'processing';
        $matching['reserve_base_from'] = '2026-11-04';

        DB::table('providers')->where('id', $madfin->id)->update(['matching' => json_encode($matching)]);
    }

    public function down(): void
    {
        $madfin = DB::table('providers')->where('code', 'madfin')->first();
        if ($madfin === null) {
            return;
        }

        $matching = json_decode((string) ($madfin->matching ?? ''), true) ?: [];
        $matching['reserve_base_from'] = '2026-11-10';

        DB::table('providers')->where('id', $madfin->id)->update(['matching' => json_encode($matching)]);
    }
};
