<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Commercial offer {{ $offer->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 12px; text-transform: uppercase; color: #6b7280; margin: 20px 0 6px; letter-spacing: .04em; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 4px; border-bottom: 1px solid #eef0f3; }
        .num { text-align: right; font-weight: bold; }
        .terms { white-space: pre-line; }
    </style>
</head>
<body>
    <div style="margin-bottom: 16px">@include('partials.pdf-logo', ['height' => 32])</div>
    <h1>Commercial offer</h1>
    <p class="muted">{{ $offer->number }} · {{ config('app.name') }} · {{ $offer->created_at?->toDateString() }}@if ($offer->valid_until) · valid until {{ $offer->valid_until->toDateString() }}@endif</p>

    <p>
        Prepared for <strong>{{ $offer->company_name }}</strong>
        @if ($offer->contact_name)<br>Attn: {{ $offer->contact_name }}@endif
        @if ($offer->currencies)<br>Processing currencies: {{ implode(', ', $offer->currencies) }}@endif
    </p>

    @php($pct = fn ($v) => $v === null ? '—' : rtrim(rtrim((string) $v, '0'), '.').'%')
    @php($fix = fn ($v) => number_format((float) $v, 2))

    <h2>Card processing</h2>
    <table>
        <tr><td>Visa — EU cards</td><td class="num">{{ $pct($offer->fee_visa_eu_percent ?? $offer->fee_acq_eu_percent) }}</td></tr>
        <tr><td>Visa — non-EU cards</td><td class="num">{{ $pct($offer->fee_visa_non_eu_percent ?? $offer->fee_acq_non_eu_percent) }}</td></tr>
        <tr><td>Mastercard — EU cards</td><td class="num">{{ $pct($offer->fee_mastercard_eu_percent ?? $offer->fee_acq_eu_percent) }}</td></tr>
        <tr><td>Mastercard — non-EU cards</td><td class="num">{{ $pct($offer->fee_mastercard_non_eu_percent ?? $offer->fee_acq_non_eu_percent) }}</td></tr>
    </table>

    <h2>Per transaction</h2>
    <table>
        <tr><td>Successful transaction</td><td class="num">{{ $fix($offer->fee_success_fixed) }}</td></tr>
        <tr><td>Declined transaction</td><td class="num">{{ $fix($offer->fee_decline_fixed) }}</td></tr>
        <tr><td>Refund</td><td class="num">{{ $fix($offer->fee_refund_fixed) }}</td></tr>
        <tr><td>Chargeback</td><td class="num">{{ $fix($offer->fee_chargeback_fixed) }}</td></tr>
    </table>
    <p class="muted">Fixed fees are charged in the currency of the processing account.</p>

    <h2>Settlement</h2>
    <table>
        <tr><td>Payout conversion to USDC</td><td class="num">{{ $pct($offer->fee_fiat_to_crypto_percent) }}</td></tr>
        <tr><td>Rolling reserve</td><td class="num">{{ $pct($offer->rolling_reserve_percent) }} for {{ $offer->rolling_reserve_days }} days</td></tr>
        @if ($offer->settlement_terms)<tr><td>Settlement cycle</td><td class="num">{{ $offer->settlement_terms }}</td></tr>@endif
        @if ((float) $offer->setup_fee > 0)<tr><td>One-time setup fee</td><td class="num">{{ $fix($offer->setup_fee) }}</td></tr>@endif
    </table>

    @if ($offer->terms)
        <h2>Terms</h2>
        <div class="terms">{{ $offer->terms }}</div>
    @endif
</body>
</html>
