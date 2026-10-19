@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@extends('pdf.layout')

@php($label = $client->invoiceName().($scope ? ' · '.$scope->name : ''))
@section('title', 'Daily report '.$label.' '.$date->toDateString())
@section('doc-title', 'Daily Processing Report')
@section('doc-sub', $label.' · '.$date->toDateString())
@section('footer', 'Daily report · '.$label.' · '.$date->toDateString())

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Client</div>
                <div class="value">{{ $client->invoiceName() }}</div>
                @if (filled($client->invoice_address))
                    <div class="muted" style="margin-top: 2px">{!! nl2br(e(trim((string) $client->invoice_address))) !!}</div>
                @endif
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">{{ $scope ? 'Company' : 'Companies' }}</td><td>{{ implode(', ', array_keys($companies)) ?: '—' }}</td></tr>
                    <tr><td class="k">MIDs</td><td>{{ array_sum(array_map(fn ($b) => count($b['rows']), $companies)) }}</td></tr>
                </table>
            </td>
            <td>
                <div class="label">Report</div>
                <div class="value">{{ $date->format('l, j F Y') }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">Generated</td><td>{{ $generatedAt->format('Y-m-d H:i') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($totals === [])
        <p class="muted">No completed reports for this day.</p>
    @else
        <table class="tiles">
            <tr>
                @foreach ($totals as $currency => $total)
                    <td>
                        <div class="tile accent">
                            <div class="t-label">Net payout {{ $currency }}</div>
                            <div class="t-value">{{ $money($total['payout'], $currency) }}</div>
                            <div class="t-hint">{{ $total['sales'] }} sales · gross {{ $money($total['turnover'], $currency) }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach ($calc['total'] as $currency => $steps)
        <h2>Payout calculation <span class="hint">· {{ $scope ? $scope->name : 'all companies' }} · all amounts in {{ $currency }}</span></h2>
        @include('reports.partials.calc', ['steps' => $steps, 'currency' => $currency])
    @endforeach

    @foreach ($companies as $company => $block)
        <h2>
            {{ $company }}
            <span class="hint">· {{ count($block['rows']) }} {{ count($block['rows']) === 1 ? 'MID' : 'MIDs' }}@foreach ($block['totals'] as $currency => $total) · {{ $currency }} net payout {{ $money($total['payout'], $currency) }}@endforeach</span>
        </h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>MID</th>
                    <th>Acquirer</th>
                    <th class="num">Sales</th>
                    <th class="num">Declines</th>
                    <th class="num">Refunds / CB</th>
                    <th class="num">Gross sales</th>
                    <th class="num">Refunds / CB amount</th>
                    <th class="num">Fees</th>
                    <th class="num">Reserve</th>
                    <th class="num">Net payout</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($block['rows'] as $row)
                    <tr>
                        <td>{{ $row['mid'] }}@if ($row['label']) <span class="muted">· {{ $row['label'] }}</span>@endif</td>
                        <td>{{ $row['acquirer'] ?? '—' }}</td>
                        <td class="num">{{ $row['sales'] }}</td>
                        <td class="num">{{ $row['declines'] }}</td>
                        <td class="num">{{ $row['refunds'] + $row['chargebacks'] }}</td>
                        <td class="num">{{ $money($row['turnover'], $row['currency']) }}</td>
                        <td class="num">{{ $money($row['deductions']) }}</td>
                        <td class="num">{{ $money($row['fees']) }}</td>
                        <td class="num">{{ $money($row['reserve']) }}</td>
                        <td class="num">{{ $money($row['payout']) }}</td>
                    </tr>
                @endforeach
                @foreach ($block['totals'] as $currency => $total)
                    <tr class="sub">
                        <td colspan="2">{{ $company }} · {{ $currency }}</td>
                        <td class="num">{{ $total['sales'] }}</td>
                        <td class="num">{{ $total['declines'] }}</td>
                        <td class="num">{{ $total['refunds'] + $total['chargebacks'] }}</td>
                        <td class="num">{{ $money($total['turnover']) }}</td>
                        <td class="num">{{ $money($total['deductions']) }}</td>
                        <td class="num">{{ $money($total['fees']) }}</td>
                        <td class="num">{{ $money($total['reserve']) }}</td>
                        <td class="num">{{ $money($total['payout']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (count($companies) > 1)
            @foreach ($calc['companies'][$company] ?? [] as $currency => $steps)
                <p class="muted" style="margin: 6px 0 2px">Calculation · {{ $company }} · {{ $currency }}</p>
                @include('reports.partials.calc', ['steps' => $steps, 'currency' => $currency])
            @endforeach
        @endif
    @endforeach

    @if (count($companies) > 1 || count($totals) > 1)
        <h2>{{ $scope ? 'Total' : 'Total for the client' }}</h2>
        <table class="grid avoid-break">
            <thead>
                <tr>
                    <th>Currency</th>
                    <th class="num">Sales</th>
                    <th class="num">Declines</th>
                    <th class="num">Refunds / CB</th>
                    <th class="num">Gross sales</th>
                    <th class="num">Refunds / CB</th>
                    <th class="num">Fees</th>
                    <th class="num">Reserve</th>
                    <th class="num">Net payout</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($totals as $currency => $total)
                    <tr class="total">
                        <td>{{ $currency }}</td>
                        <td class="num">{{ $total['sales'] }}</td>
                        <td class="num">{{ $total['declines'] }}</td>
                        <td class="num">{{ $total['refunds'] + $total['chargebacks'] }}</td>
                        <td class="num">{{ $money($total['turnover']) }}</td>
                        <td class="num">{{ $money($total['deductions']) }}</td>
                        <td class="num">{{ $money($total['fees']) }}</td>
                        <td class="num">{{ $money($total['reserve']) }}</td>
                        <td class="num">{{ $money($total['payout']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($pending !== [])
        <div class="note">
            Not included yet: @foreach ($pending as $item){{ $item['mid'] }} ({{ $item['status'] }}){{ $loop->last ? '' : ', ' }}@endforeach. These reports are still waiting for provider data.
        </div>
    @endif

    <div class="note">
        Net payout is added to the balance of the relevant company and paid out with the next settlement statement.
        Fees include processing, per-transaction fees and conversion. Amounts are rounded to cents per total.
    </div>
@endsection
