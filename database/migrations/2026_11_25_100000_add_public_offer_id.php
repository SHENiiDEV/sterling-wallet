<?php

use App\Models\CommercialOffer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number the client sees on the proposal: a random SP-12345678, not the running OFF-2026-0003 that tells
     * how many offers were made. `number` stays for the team.
     */
    public function up(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->string('public_offer_id', 32)->nullable()->unique()->after('number');
        });

        CommercialOffer::query()->whereNull('public_offer_id')->each(
            fn (CommercialOffer $offer) => $offer->forceFill(['public_offer_id' => CommercialOffer::newPublicId()])->saveQuietly(),
        );
    }

    public function down(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table) {
            $table->dropColumn('public_offer_id');
        });
    }
};
