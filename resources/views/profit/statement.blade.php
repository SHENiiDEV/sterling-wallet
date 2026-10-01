@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@php($pct = fn ($v) => \App\Support\PdfRenderer::percent($v))
@extends('pdf.layout')

@section('title', 'Profit share '.$statement->month)
@section('doc-title', 'Profit Share Statement')
@section('doc-sub')
    {{ $monthLabel }} &nbsp;<span class="pill pill-{{ $closed ? 'settled' : 'draft' }}">{{ $closed ? 'closed' : 'draft' }}</span>
@endsection
@section('footer', 'Profit share · '.$monthLabel.' · internal')
@if (! $closed)
    @section('watermark', 'DRAFT')
@endif

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Period</div>
                <div class="value">{{ $monthLabel }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">Source</td><td>Completed daily reports</td></tr>
                    <tr><td class="k">Currency</td><td>{{ $currency }} (converted per report)</td></tr>
                </table>
            </td>
            <td>
                <div class="label">Statement</div>
                <div class="value">{{ $closed ? 'Closed — final' : 'Draft — may still change' }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">Calculated</td><td>{{ $statement->calculated_at?->timezone(config('sterling.timezone'))->format('Y-m-d H:i') ?? '—' }}</td></tr>
                    @if ($closed)
                        <tr><td class="k">Closed</td><td>{{ $statement->closed_at?->timezone(config('sterling.timezone'))->format('Y-m-d H:i') }} by {{ $statement->closer->name ?? '—' }}</td></tr>
                    @endif
                    <tr><td class="k">Partners</td><td>{{ count($partners) }}</td></tr>
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
                        <div class="t-value" @if ($accent && $negative) style="color: #fca5a5" @endif>{{ $value }}</div>
                        <div class="t-hint">{{ $hint }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    @if ($negative)
        <div class="note" style="border-left-color: #b42318; margin: -6px 0 10px">
            <strong class="neg">Shares exceed net profit this month.</strong> Check the rules on turnover-based partners.
        </div>
    @endif

    <h2>Calculation <span class="hint">· {{ $currency }}</span></h2>
    <table class="calc avoid-break">
        <tr>
            <td class="step">1</td>
            <td style="width: 34%">Net profit</td>
            <td class="detail">Merchant fees + conversion − bank, gateway and crypto costs</td>
            <td class="amount">{{ $money($statement->net_profit) }}</td>
        </tr>
        @foreach ($partners as $i => $partner)
            <tr>
                <td class="step">{{ $i + 2 }}</td>
                <td>{{ $partner['name'] }}</td>
                <td class="detail">{{ $partner['merchants'] }} merchant(s) · {{ $partner['of_profit'] }} of net profit</td>
                <td class="amount neg">{{ $money($partner['share']->negated()) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td class="step">=</td>
            <td>Company remainder</td>
            <td class="detail">Kept by {{ config('app.name') }}</td>
            <td class="amount">{{ $money($statement->company_remainder, $currency) }}</td>
        </tr>
    </table>

    @if ($merchants !== [])
        <h2>Profit by merchant</h2>
        <table class="grid avoid-break">
            <thead>
                <tr>
                    <th>Merchant</th>
                    <th class="num">Turnover</th>
                    <th class="num">Net profit</th>
                    <th class="num">Margin</th>
                    <th class="num">Partner shares</th>
                    <th class="num">Remainder</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($merchants as $m)
                    <tr>
                        <td>{{ $m['name'] }}</td>
                        <td class="num">{{ $money($m['turnover']) }}</td>
                        <td class="num">{{ $money($m['net_profit']) }}</td>
                        <td class="num muted">{{ $m['margin'] }}</td>
                        <td @class(['num', 'neg' => ! $m['shares']->isZero()])>{{ $m['shares']->isZero() ? '0.00' : $money($m['shares']->negated()) }}</td>
                        <td @class(['num', 'strong', 'neg' => $m['remainder']->isNegative()])>{{ $money($m['remainder']) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td>Total</td>
                    <td class="num">{{ $money($statement->turnover) }}</td>
                    <td class="num">{{ $money($statement->net_profit) }}</td>
                    <td></td>
                    <td class="num">{{ $money(\Brick\Math\BigDecimal::of((string) $statement->shares_total)->negated()) }}</td>
                    <td class="num">{{ $money($statement->company_remainder, $currency) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($partners === [])
        <div class="note">No profit share rules apply to this month's reports.</div>
    @else
        <h2>Shares by partner</h2>
        @foreach ($partners as $partner)
            <table class="grid avoid-break" style="margin-bottom: 10px">
                <thead>
                    <tr>
                        <th style="width: 34%">{{ $partner['name'] }}</th>
                        <th>Base</th>
                        <th class="num">Base amount</th>
                        <th class="num">Rate</th>
                        <th class="num">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($partner['lines'] as $line)
                        <tr>
                            <td>{{ $line->merchant_name }}</td>
                            <td class="muted">{{ $line->base->label() }}</td>
                            <td class="num">{{ $money($line->base_amount) }}</td>
                            <td class="num">{{ $pct($line->percent) }}</td>
                            <td class="num">{{ $money($line->share) }}</td>
                        </tr>
                    @endforeach
                    <tr class="sub">
                        <td colspan="4">Total {{ $partner['name'] }}</td>
                        <td class="num">{{ $money($partner['share'], $currency) }}</td>
                    </tr>
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
