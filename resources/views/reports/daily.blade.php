@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@php($percent = fn ($v) => \App\Support\PdfRenderer::percent($v))
@extends('pdf.layout')

@section('title', 'Daily report '.$task->merchantMid->mid.' '.$task->report_date->toDateString())
@section('doc-title', 'Daily Processing Report')
@section('doc-sub', $task->report_date->toDateString().' · MID '.$task->merchantMid->mid)
@section('footer', 'Daily report · '.$merchant->name.' · MID '.$task->merchantMid->mid.' · '.$task->report_date->toDateString())

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Merchant</div>
                <div class="value">{{ $merchant->company->name ?? $merchant->name }}</div>
                <table class="kv" style="margin-top: 4px">
                    @if (($merchant->company->name ?? $merchant->name) !== $merchant->name)
                        <tr><td class="k">Merchant</td><td>{{ $merchant->name }}</td></tr>
                    @endif
                    <tr><td class="k">Merchant ID</td><td>{{ $merchant->public_id }}</td></tr>
                    @if ($merchant->website)
                        <tr><td class="k">Website</td><td>{{ $merchant->website }}</td></tr>
                    @endif
                </table>
            </td>
            <td>
                <div class="label">Report</div>
                <div class="value">{{ $task->report_date->format('l, j F Y') }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">MID</td><td>{{ $task->merchantMid->mid }}@if ($acquirer) <span class="muted">· {{ $acquirer }}</span>@endif</td></tr>
                    <tr>
                        <td class="k">Period</td>
                        <td>
                            {{ $task->period_from->toDateString() }}@if (! $task->period_from->equalTo($task->period_to)) — {{ $task->period_to->toDateString() }} <span class="muted">(incl. non-working days)</span>@endif
                        </td>
                    </tr>
                    <tr><td class="k">Currency</td><td>{{ $currency }}</td></tr>
                    <tr><td class="k">Generated</td><td>{{ ($task->generated_at ?? now())->timezone(config('sterling.timezone', 'Europe/Riga'))->format('Y-m-d H:i') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            @foreach ($tiles as [$label, $value, $hint, $accent])
                <td>
                    <div @class(['tile', 'accent' => $accent])>
                        <div class="t-label">{{ $label }}</div>
                        <div class="t-value">{{ $value }}</div>
                        <div class="t-hint">{{ $hint }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="strip">
        <tr>
            <td><span>Approved</span> {{ $counts['sales'] }}</td>
            <td><span>Declined</span> {{ $counts['declines'] }}</td>
            <td><span>Approval rate</span> {{ $approvalRate === null ? '—' : $approvalRate.'%' }}</td>
            <td><span>Average ticket</span> {{ $averageTicket === null ? '—' : $money($averageTicket, $currency) }}</td>
            <td><span>Refunds</span> {{ $counts['refunds'] }}</td>
            <td><span>Chargebacks</span> {{ $counts['chargebacks'] }}</td>
        </tr>
    </table>

    <h2>Payout calculation <span class="hint">· all amounts in {{ $currency }}</span></h2>
    <table class="calc avoid-break">
        @php($n = 0)
        @foreach ($steps as $step)
            <tr @class(['subtotal' => $step['kind'] === 'subtotal', 'total' => $step['kind'] === 'total'])>
                <td class="step">
                    @if (in_array($step['kind'], ['plus', 'minus'], true))
                        {{ ++$n }}
                    @else
                        =
                    @endif
                </td>
                <td style="width: 26%">{{ $step['label'] }}</td>
                <td class="detail">{{ $step['detail'] }}</td>
                <td @class(['amount', 'neg' => $step['kind'] === 'minus' && ! $step['amount']->isZero()])>
                    {{ $money($step['amount']) }}
                </td>
            </tr>
        @endforeach
    </table>

    <table class="avoid-break" style="margin-top: 4px">
        <tr>
            <td style="width: 57%; padding-right: 12px">
                <h2><span class="letter">A</span> Processing fee by card scheme</h2>
                <table class="grid">
                    <thead>
                        <tr>
                            <th>Card scheme</th>
                            <th class="num">Sales</th>
                            <th class="num">Volume</th>
                            <th class="num">Rate</th>
                            <th class="num">Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schemes as $row)
                            <tr @class(['muted' => $row['count'] === 0])>
                                <td>{{ $row['label'] }}</td>
                                <td class="num">{{ $row['count'] }}</td>
                                <td class="num">{{ $money($row['amount']) }}</td>
                                <td class="num">{{ $percent($row['rate']) }}</td>
                                <td class="num">{{ $money($row['fee']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="sub">
                            <td>Total</td>
                            <td class="num">{{ array_sum(array_column($schemes, 'count')) }}</td>
                            <td class="num">{{ $money($task->turnover) }}</td>
                            <td></td>
                            <td class="num">{{ $money($percentFee) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
            <td style="width: 43%">
                <h2><span class="letter">B</span> Transaction fees</h2>
                <table class="grid">
                    <thead>
                        <tr>
                            <th>Operation</th>
                            <th class="num">Count</th>
                            <th class="num">Each</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactionFees as $row)
                            <tr @class(['muted' => $row['count'] === 0])>
                                <td>{{ $row['label'] }}</td>
                                <td class="num">{{ $row['count'] }}</td>
                                <td class="num">{{ $money($row['unit']) }}</td>
                                <td class="num">{{ $money($row['total']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="sub">
                            <td>Total</td>
                            <td class="num">{{ array_sum(array_column($transactionFees, 'count')) }}</td>
                            <td></td>
                            <td class="num">{{ $money($fixedFee) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div class="note">
        The net payout is added to your balance and paid out with the next settlement statement.
        Rolling reserve is held on the MID and released automatically after the holding period.
        Success and decline fees are counted on the payment gateway. Amounts are rounded to cents per total.
    </div>

    @if ($operationsTotal > 0)
        <div class="page-break"></div>
        <h2 style="margin-top: 0">
            Transactions
            <span class="hint">· {{ $operationsTotal }} {{ $operationsTotal === 1 ? 'operation' : 'operations' }}@if ($operationsTotal > count($operations)), first {{ count($operations) }} shown — full list in the CSV attachment @endif</span>
        </h2>
        <table class="grid compact">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Time (UTC)</th>
                    <th>Type</th>
                    <th>Payment ID</th>
                    <th>Card</th>
                    <th>Scheme</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($operations as $op)
                    @php($isSale = $op->operation_type === \App\Enums\OperationType::Sale)
                    <tr>
                        <td class="muted">{{ $loop->iteration }}</td>
                        <td>{{ $op->transaction_at?->format('Y-m-d H:i') ?? $op->report_date?->toDateString() }}</td>
                        <td>{{ ucfirst($op->operation_type->value) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($op->payment_id, 28) }}</td>
                        <td>{{ $op->card_bin || $op->card_last4 ? ($op->card_bin ?? '').' •••• '.($op->card_last4 ?? '') : '—' }}</td>
                        <td>{{ $op->ips ? ucfirst($op->ips) : '—' }}{{ $op->region ? ' · '.($op->region === 'eu' ? 'EU' : 'Non-EU') : '' }}</td>
                        <td @class(['num', 'neg' => ! $isSale])>{{ $money($isSale ? $op->amount : '-'.ltrim((string) $op->amount, '-')) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
