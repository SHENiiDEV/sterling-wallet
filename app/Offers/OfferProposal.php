<?php

namespace App\Offers;

use App\Models\CommercialOffer;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The commercial proposal PDF: cover, about Sterling Pay, card acquiring
 * pricing, other charges and terms. Company texts come from
 * `config('sterling.proposal')`, rates and extra charges from the offer.
 */
class OfferProposal
{
    private const SYMBOLS = ['EUR' => '€', 'GBP' => '£', 'USD' => '$'];

    public function render(CommercialOffer $offer): string
    {
        return PdfRenderer::render('offers.offer', $this->data($offer), pageNumbers: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(CommercialOffer $offer): array
    {
        $currency = $offer->fee_currency ?? 'EUR';
        $fixed = fn ($value) => $this->fixed($value, $currency);
        $rate = fn ($percent) => $percent === null ? 'N/A' : $this->percent($percent);

        // Everything the merchant pays, in one table: card rates, then per
        // transaction fees, then reserve and one-off fees.
        $acquiring = [
            ['MASTERCARD', 'Merchant Discount Rate — EEA issued cards', $rate($offer->fee_mastercard_eu_percent)],
            ['MASTERCARD', 'Merchant Discount Rate — Non-EEA issued cards', $rate($offer->fee_mastercard_non_eu_percent)],
            ['VISA', 'Merchant Discount Rate — EEA issued cards', $rate($offer->fee_visa_eu_percent)],
            ['VISA', 'Merchant Discount Rate — Non-EEA issued cards', $rate($offer->fee_visa_non_eu_percent)],
            [null, 'Transaction fee — approved', $fixed($offer->fee_success_fixed)],
            [null, 'Transaction fee — declined', $fixed($offer->fee_decline_fixed)],
            [null, 'Refund', $fixed($offer->fee_refund_fixed)],
            [null, 'Chargeback', $fixed($offer->fee_chargeback_fixed)],
            ...($offer->fee_collab_fixed !== null ? [[null, 'Collab', $fixed($offer->fee_collab_fixed)]] : []),
            [null, 'Rolling reserve', $this->percent($offer->rolling_reserve_percent).' ('.$offer->rolling_reserve_days.' days)'],
            ...($offer->rolling_reserve_cap !== null && BigDecimal::of((string) $offer->rolling_reserve_cap)->isPositive()
                ? [[null, 'Rolling reserve cap', $fixed($offer->rolling_reserve_cap)]]
                : []),
            [null, 'Setup fee', $fixed($offer->setup_fee)],
        ];
        foreach ($offer->extra_fees ?? [] as $extra) {
            $acquiring[] = [null, $extra['label'], $extra['value']];
        }

        $settlement = array_values(array_filter([
            ['Payout currency', 'USDC'],
            ['Conversion to USDC', $this->percent($offer->fee_fiat_to_crypto_percent)],
            $offer->settlement_terms ? ['Settlement cycle', $offer->settlement_terms] : null,
            $offer->currencies ? ['Processing currencies', implode(', ', $offer->currencies)] : null,
        ]));

        return [
            'offer' => $offer,
            'proposal' => config('sterling.proposal'),
            'acquiring' => $acquiring,
            'settlement' => $settlement,
            'currency' => $currency,
        ];
    }

    /**
     * "€0.30", "€1,000.00", "€0.00".
     */
    private function fixed(mixed $value, string $currency): string
    {
        $amount = BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);

        $symbol = self::SYMBOLS[$currency] ?? null;
        $text = PdfRenderer::money($amount);

        return $symbol ? $symbol.$text : $text.' '.$currency;
    }

    private function percent(mixed $value): string
    {
        return PdfRenderer::percent($value);
    }
}
