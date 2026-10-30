<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cardaq clears hours after the gateway sees a payment, so rows left with the same card and amount are paired
     * in time order (the other matching rules of the provider stay as they are).
     */
    public function up(): void
    {
        $cardaq = DB::table('providers')->where('code', 'cardaq')->first();
        if ($cardaq === null) {
            return;
        }

        $matching = json_decode((string) ($cardaq->matching ?? ''), true) ?: [];
        $matching['pair_leftovers'] = true;

        DB::table('providers')->where('id', $cardaq->id)->update(['matching' => json_encode($matching)]);
    }

    public function down(): void
    {
        $cardaq = DB::table('providers')->where('code', 'cardaq')->first();
        if ($cardaq === null) {
            return;
        }

        $matching = json_decode((string) ($cardaq->matching ?? ''), true) ?: [];
        unset($matching['pair_leftovers']);

        DB::table('providers')->where('id', $cardaq->id)->update(['matching' => $matching === [] ? null : json_encode($matching)]);
    }
};
