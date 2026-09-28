<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Profit share {{ $statement->month }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { text-align: left; font-size: 9.5px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eef0f3; }
        .num { text-align: right; white-space: nowrap; }
        .warn { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Profit share · {{ $statement->month }}</h1>
    <p class="muted">{{ config('app.name') }} · {{ ucfirst($statement->status->value) }} · amounts in {{ $statement->base_currency }}</p>

    <table>
        <tr><td>Turnover</td><td class="num">{{ number_format((float) $statement->turnover, 2) }}</td></tr>
        <tr><td>Net profit</td><td class="num">{{ number_format((float) $statement->net_profit, 2) }}</td></tr>
        <tr><td>Partner shares</td><td class="num">{{ number_format((float) $statement->shares_total, 2) }}</td></tr>
        <tr>
            <td><strong>Company remainder</strong></td>
            <td class="num {{ (float) $statement->company_remainder < 0 ? 'warn' : '' }}"><strong>{{ number_format((float) $statement->company_remainder, 2) }}</strong></td>
        </tr>
    </table>
    @if ((float) $statement->company_remainder < 0)
        <p class="warn">Warning: shares exceed net profit this month.</p>
    @endif

    <table>
        <thead>
            <tr><th>Partner</th><th>Merchant</th><th>Base</th><th class="num">Base amount</th><th class="num">%</th><th class="num">Share</th></tr>
        </thead>
        <tbody>
            @forelse ($statement->lines->sortBy(['partner_name', 'merchant_name']) as $line)
                <tr>
                    <td>{{ $line->partner_name }}</td>
                    <td>{{ $line->merchant_name }}</td>
                    <td>{{ $line->base->label() }}</td>
                    <td class="num">{{ number_format((float) $line->base_amount, 2) }}</td>
                    <td class="num">{{ rtrim(rtrim($line->percent, '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->share, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No shares this month.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
