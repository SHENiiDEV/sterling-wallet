@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@php($status = $settlement->status->value)
@php($payoutCurrency = $settlement->payout_currency)
@extends('pdf.layout')

@section('title', 'Settlement Statement '.$settlement->number)
@section('doc-title', 'Settlement Statement')
@section('doc-sub')
    {{ $settlement->number }} &nbsp;<span class="pill pill-{{ $status }}">{{ $status }}</span>
@endsection
@section('footer', 'Settlement '.$settlement->number.' · '.$company)
@if (in_array($status, ['draft', 'cancelled'], true))
    @section('watermark', strtoupper($status))
@endif

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Paid to</div>
                <div class="value">{{ $company }}</div>
                <table class="kv" style="margin-top: 4px">
                    @if ($company !== $settlement->merchant->name)
                        <tr><td class="k">Merchant</td><td>{{ $settlement->merchant->name }}</td></tr>
                    @endif
                    <tr><td class="k">Merchant ID</td><td>{{ $settlement->merchant->public_id }}</td></tr>
                    @if ($settlement->wallet)
                        <tr><td class="k">Wallet</td><td>{{ trim($settlement->wallet->currency.' '.$settlement->wallet->network) }}</td></tr>
                        <tr><td class="k">Address</td><td style="font-size: 7.5px; word-break: break-all">{{ $settlement->wallet->address }}</td></tr>
                    @endif
                </table>
            </td>
            <td>
                <div class="label">Statement</div>
                <div class="value">{{ $settlement->number }}</div>
                <table class="kv" style="margin-top: 4px">
                    <tr><td class="k">Issued</td><td>{{ ($settlement->settled_at ?? $settlement->approved_at ?? now())->timezone(config('sterling.timezone', 'Europe/Riga'))->format('Y-m-d') }}</td></tr>
                    <tr><td class="k">Reports period</td><td>{{ $periodFrom ? $periodFrom->toDateString().' — '.$periodTo->toDateString() : '—' }}</td></tr>
                    <tr><td class="k">Payout currency</td><td>{{ $payoutCurrency }}</td></tr>
                    <tr><td class="k">Status</td><td>{{ ucfirst($status) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="tiles tall">
        <tr>
            @foreach ($tiles as [$label, $value, $hint, $accent])
                <td style="width: 25%">
                    <div @class(['tile', 'accent' => $accent])>
                        <div class="t-label">{{ $label }}</div>
                        @foreach ((array) $value as $line)
                            <div class="t-value" @if (count((array) $value) > 1) style="font-size: 10px; margin-top: 1px" @endif>{{ $line }}</div>
                        @endforeach
                        <div class="t-hint">{{ $hint }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    @foreach ($reportGroups as $group)
        <h2>Daily reports · {{ $group['currency'] }} <span class="hint">· {{ count($group['rows']) }} {{ count($group['rows']) === 1 ? 'report' : 'reports' }}</span></h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>MID</th>
                    <th class="num">Sales</th>
                    <th class="num">Gross sales</th>
                    <th class="num">Refunds + CB</th>
                    <th class="num">Fees</th>
                    <th class="num">Reserve</th>
                    <th class="num">Net payout</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['mid'] }}</td>
                        <td class="num">{{ $row['sales'] }}</td>
                        <td class="num">{{ $money($row['turnover']) }}</td>
                        <td @class(['num', 'neg' => ! $row['refunds']->isZero()])>{{ $row['refunds']->isZero() ? '0.00' : $money($row['refunds']->negated()) }}</td>
                        <td @class(['num', 'neg' => ! $row['fees']->isZero()])>{{ $row['fees']->isZero() ? '0.00' : $money($row['fees']->negated()) }}</td>
                        <td @class(['num', 'neg' => ! $row['reserve']->isZero()])>{{ $row['reserve']->isZero() ? '0.00' : $money($row['reserve']->negated()) }}</td>
                        <td class="num strong">{{ $money($row['payout']) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td colspan="2">Total {{ $group['currency'] }}</td>
                    <td class="num">{{ $group['totals']['sales'] }}</td>
                    <td class="num">{{ $money($group['totals']['turnover']) }}</td>
                    <td class="num">{{ $money($group['totals']['refunds']->negated()) }}</td>
                    <td class="num">{{ $money($group['totals']['fees']->negated()) }}</td>
                    <td class="num">{{ $money($group['totals']['reserve']->negated()) }}</td>
                    <td class="num">{{ $money($group['totals']['payout'], $group['currency']) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    @if ($releases->isNotEmpty())
        <h2>Rolling reserve released <span class="hint">· held earlier, now paid out</span></h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>Released</th>
                    <th>MID</th>
                    <th>Held for</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($releases as $line)
                    <tr>
                        <td>{{ $line->reserveEntry?->created_at?->toDateString() ?? '—' }}</td>
                        <td>{{ $line->reserveEntry?->merchantMid?->mid ?? '—' }}</td>
                        <td>{{ $line->reserveEntry?->note ?? $line->description }}</td>
                        <td class="num pos">+{{ $money($line->amount, $line->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($adjustments->isNotEmpty())
        <h2>Adjustments</h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($adjustments as $line)
                    @php($negative = str_starts_with((string) $line->amount, '-'))
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td @class(['num', 'neg' => $negative, 'pos' => ! $negative])>{{ $negative ? '' : '+' }}{{ $money($line->amount, $line->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="avoid-break">
        <h2>Final calculation <span class="hint">· converted to {{ $payoutCurrency }}</span></h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>Currency</th>
                    <th class="num">Reports</th>
                    <th class="num">Reserve released</th>
                    <th class="num">Adjustments</th>
                    <th class="num">Balance</th>
                    <th class="num">Rate</th>
                    <th class="num">{{ $payoutCurrency }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($conversion as $row)
                    <tr>
                        <td class="strong">{{ $row['currency'] }}</td>
                        <td class="num">{{ $money($row['reports']) }}</td>
                        <td class="num">{{ $money($row['releases']) }}</td>
                        <td class="num">{{ $money($row['adjustments']) }}</td>
                        <td class="num strong">{{ $money($row['amount'], $row['currency']) }}</td>
                        <td class="num">
                            @if ($row['rate'] === null)
                                <span class="neg">not set</span>
                            @else
                                1 {{ $row['currency'] }} = {{ rtrim(rtrim((string) $row['rate'], '0'), '.') }} {{ $payoutCurrency }}
                            @endif
                        </td>
                        <td class="num strong">{{ $money($row['payout']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="6">Total payout</td>
                    <td class="num">{{ $money($settlement->total_payout, $payoutCurrency) }}</td>
                </tr>
            </tbody>
        </table>

        <table style="margin-top: 14px">
            <tr>
                <td style="width: 50%; padding-right: 10px">
                    <div class="note" style="margin-top: 0">
                        <strong>Approval</strong><br>
                        @if ($settlement->approved_at)
                            Approved {{ $settlement->approved_at->timezone(config('sterling.timezone', 'Europe/Riga'))->format('Y-m-d H:i') }} by {{ $settlement->approver->name ?? '—' }}
                        @elseif ($status === 'cancelled')
                            Cancelled{{ $settlement->cancel_reason ? ': '.$settlement->cancel_reason : '' }}
                        @else
                            Draft — not approved yet. Amounts may still change.
                        @endif
                    </div>
                </td>
                <td style="width: 50%">
                    <div class="note" style="margin-top: 0">
                        <strong>Payment</strong><br>
                        @if ($settlement->settled_at)
                            Paid {{ $settlement->settled_at->timezone(config('sterling.timezone', 'Europe/Riga'))->format('Y-m-d H:i') }} by {{ $settlement->settler->name ?? '—' }}
                        @else
                            Not paid yet.
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        @if ($settlement->tx_hash)
            <table class="txbox">
                <tr>
                    <td>
                        <div class="t-label">Transaction hash{{ $settlement->wallet ? ' · '.trim($settlement->wallet->currency.' '.$settlement->wallet->network) : '' }}</div>
                        <div class="hash">{{ $settlement->tx_hash }}</div>
                        @if ($explorerUrl)
                            <a href="{{ $explorerUrl }}">View on block explorer</a>
                        @endif
                    </td>
                    <td class="num" style="width: 30%; vertical-align: middle">
                        <div class="t-label">Amount sent</div>
                        <div class="amount">{{ $money($settlement->total_payout, $payoutCurrency) }}</div>
                    </td>
                </tr>
            </table>
        @endif

        @if ($settlement->notes)
            <div class="note">{{ $settlement->notes }}</div>
        @endif
    </div>
@endsection
