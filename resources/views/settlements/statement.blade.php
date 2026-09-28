<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Settlement Statement {{ $settlement->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #6b7280; }
        .head { width: 100%; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th { text-align: left; font-size: 9.5px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
        table.lines td { padding: 6px 4px; border-bottom: 1px solid #eef0f3; }
        .num { text-align: right; white-space: nowrap; }
        tr.total td { border-top: 2px solid #111827; font-weight: bold; font-size: 12px; }
        .box { margin-top: 18px; padding: 10px; border: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div style="margin-bottom: 10px">@include('partials.pdf-logo')</div>
                <h1>Settlement Statement</h1>
                <div class="muted">{{ $settlement->number }} · {{ ucfirst($settlement->status->value) }}</div>
            </td>
            <td style="text-align: right">
                <strong>{{ config('app.name') }}</strong><br>
                <span class="muted">Issued {{ now()->toDateString() }}</span>
            </td>
        </tr>
    </table>

    <p>
        <strong>{{ $settlement->merchant->company->name ?? $settlement->merchant->name }}</strong><br>
        Merchant: {{ $settlement->merchant->name }} ({{ $settlement->merchant->public_id }})
    </p>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Amount</th>
                <th class="num">Rate</th>
                <th class="num">{{ $settlement->payout_currency }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($settlement->lines->sortBy('id') as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ number_format((float) $line->amount, 2) }} {{ $line->currency }}</td>
                    <td class="num">{{ rtrim(rtrim($line->rate, '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->amount_payout, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Total payout</td>
                <td class="num">{{ number_format((float) $settlement->total_payout, 2) }} {{ $settlement->payout_currency }}</td>
            </tr>
        </tbody>
    </table>

    <div class="box">
        @if ($settlement->wallet)
            Paid to: {{ $settlement->wallet->currency }} {{ $settlement->wallet->network }} · {{ $settlement->wallet->address }}<br>
        @endif
        @if ($settlement->approved_at)
            Approved: {{ $settlement->approved_at->toDateTimeString() }} by {{ $settlement->approver->name ?? '—' }}<br>
        @endif
        @if ($settlement->settled_at)
            Paid: {{ $settlement->settled_at->toDateTimeString() }} by {{ $settlement->settler->name ?? '—' }}<br>
            Transaction: {{ $settlement->tx_hash }}
        @endif
        @if ($settlement->notes)
            <br>{{ $settlement->notes }}
        @endif
    </div>
</body>
</html>
