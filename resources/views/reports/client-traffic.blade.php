@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@php($amounts = fn (array $by) => collect($by)->map(fn ($v, $c) => $money($v, $c))->implode(' · ') ?: '—')
@extends('pdf.layout')

@php($label = $title)
@php($range = $from->format('j M Y').' – '.$to->format('j M Y'))
@section('title', 'Traffic '.$label.' '.$range)
@section('doc-title', 'Traffic Report')
@section('doc-sub', $label.' · '.$range)
@section('footer', 'Traffic · '.$label.' · '.$range)

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Client</div>
                <div class="value">{{ $title }}</div>
            </td>
            <td>
                <div class="label">Period</div>
                <div class="value">{{ $range }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">Generated</td><td>{{ $generatedAt->format('Y-m-d H:i') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($totals['attempts'] === 0)
        <p class="muted">No payments in this period.</p>
    @else
        <table class="tiles">
            <tr>
                <td><div class="tile"><div class="t-label">Attempts</div><div class="t-value">{{ number_format($totals['attempts']) }}</div></div></td>
                <td><div class="tile"><div class="t-label">Successful</div><div class="t-value">{{ number_format($totals['successful']) }}</div></div></td>
                <td><div class="tile"><div class="t-label">Declined</div><div class="t-value">{{ number_format($totals['declined']) }}</div></div></td>
                <td><div class="tile accent"><div class="t-label">Approval rate</div><div class="t-value">{{ $totals['rate'] }}%</div></div></td>
            </tr>
        </table>
        <table class="kv" style="margin-bottom: 6px">
            <tr><td class="k">Successful amount</td><td class="strong">{{ $amounts($totals['amount']) }}</td></tr>
            <tr><td class="k">Average ticket</td><td>{{ $amounts($average) }}</td></tr>
        </table>

        <h2>By day</h2>
        <table class="grid avoid-break">
            <thead>
                <tr>
                    <th>Day</th>
                    <th class="num">Attempts</th>
                    <th class="num">Successful</th>
                    <th class="num">Declined</th>
                    <th class="num">Approval</th>
                    <th class="num">Successful amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day => $d)
                    <tr>
                        <td>{{ \Carbon\CarbonImmutable::parse($day)->format('D, j M') }}</td>
                        <td class="num">{{ number_format($d['attempts']) }}</td>
                        <td class="num">{{ number_format($d['successful']) }}</td>
                        <td class="num">{{ number_format($d['declined']) }}</td>
                        <td class="num">{{ $d['rate'] }}%</td>
                        <td class="num">{{ $amounts($d['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td>Total</td>
                    <td class="num">{{ number_format($totals['attempts']) }}</td>
                    <td class="num">{{ number_format($totals['successful']) }}</td>
                    <td class="num">{{ number_format($totals['declined']) }}</td>
                    <td class="num">{{ $totals['rate'] }}%</td>
                    <td class="num">{{ $amounts($totals['amount']) }}</td>
                </tr>
            </tbody>
        </table>

        @if ($reasons !== [])
            <h2>Why payments were declined</h2>
            <table class="grid avoid-break">
                <thead>
                    <tr>
                        <th>Reason</th>
                        <th class="num">Declined</th>
                        <th class="num">Share</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reasons as $r)
                        <tr>
                            <td>{{ ucfirst($r['reason']) }}</td>
                            <td class="num">{{ number_format($r['count']) }}</td>
                            <td class="num">{{ $r['share'] }}%</td>
                            <td class="num">{{ $amounts($r['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <table class="meta" style="margin-top: 4px">
            <tr>
                <td style="vertical-align: top">
                    <h2>By card scheme</h2>
                    <table class="grid compact">
                        <thead><tr><th>Scheme</th><th class="num">Attempts</th><th class="num">Successful</th><th class="num">Approval</th></tr></thead>
                        <tbody>
                            @foreach ($schemes as $s)
                                <tr><td>{{ ucfirst($s['name']) }}</td><td class="num">{{ number_format($s['attempts']) }}</td><td class="num">{{ number_format($s['successful']) }}</td><td class="num">{{ $s['rate'] }}%</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
                <td style="vertical-align: top">
                    <h2>By issuer country <span class="hint">· top {{ count($countries) }}</span></h2>
                    <table class="grid compact">
                        <thead><tr><th>Country</th><th class="num">Attempts</th><th class="num">Successful</th><th class="num">Approval</th></tr></thead>
                        <tbody>
                            @foreach ($countries as $s)
                                <tr><td>{{ $s['name'] }}</td><td class="num">{{ number_format($s['attempts']) }}</td><td class="num">{{ number_format($s['successful']) }}</td><td class="num">{{ $s['rate'] }}%</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

        @if (count($mids) > 1)
            <h2>By terminal</h2>
            <table class="grid avoid-break">
                <thead>
                    <tr>
                        <th>MID</th>
                        <th>Merchant</th>
                        <th class="num">Attempts</th>
                        <th class="num">Successful</th>
                        <th class="num">Declined</th>
                        <th class="num">Approval</th>
                        <th class="num">Successful amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mids as $m)
                        <tr>
                            <td>{{ $m['mid'] }}</td>
                            <td>{{ $m['merchant'] }}</td>
                            <td class="num">{{ number_format($m['attempts']) }}</td>
                            <td class="num">{{ number_format($m['successful']) }}</td>
                            <td class="num">{{ number_format($m['declined']) }}</td>
                            <td class="num">{{ $m['rate'] }}%</td>
                            <td class="num">{{ $amounts($m['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <div class="note">
        Approval rate = successful ÷ attempts. Routing errors (payments that never reached a bank){{ $routingErrors > 0 ? ', '.number_format($routingErrors).' in this period,' : '' }} are not counted.
        Days are by {{ config('sterling.timezone') }} time.
    </div>
@endsection
