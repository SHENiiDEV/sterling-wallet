@php($money = fn ($v, $c = null) => \App\Support\PdfRenderer::money($v, $c))
@php($pct = fn ($v) => \App\Support\PdfRenderer::percent($v))
@extends('pdf.layout')

@section('title', 'Partner statement '.$partner.' '.$statement->month)
@section('doc-title', 'Partner Statement')
@section('doc-sub')
    {{ $monthLabel }} &nbsp;<span class="pill pill-{{ $closed ? 'settled' : 'draft' }}">{{ $closed ? 'final' : 'preliminary' }}</span>
@endsection
@section('footer', 'Partner statement · '.$partner.' · '.$monthLabel)
@if (! $closed)
    @section('watermark', 'DRAFT')
@endif

@section('content')
    <table class="meta">
        <tr>
            <td>
                <div class="label">Prepared for</div>
                <div class="value">{{ $partner }}</div>
            </td>
            <td>
                <div class="label">Period</div>
                <div class="value">{{ $monthLabel }}</div>
                <div class="muted" style="margin-top: 2px">Amounts in {{ $currency }}</div>
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

    <h2>Your share by merchant</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width: 34%">Merchant</th>
                <th>Base</th>
                <th class="num">Base amount</th>
                <th class="num">Rate</th>
                <th class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->merchant_name }}</td>
                    <td class="muted">{{ $line->base->label() }}</td>
                    <td class="num">{{ $money($line->base_amount) }}</td>
                    <td class="num">{{ $pct($line->percent) }}</td>
                    <td class="num strong">{{ $money($line->share) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="4">Total for {{ $monthLabel }}</td>
                <td class="num">{{ $money($total, $currency) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        Share = base amount × rate. The base is the merchant's net profit (our fees minus bank, gateway and crypto costs)
        or its turnover, as agreed for each merchant, summed over the completed daily reports of the month.
        @if (! $closed)
            <br><strong>Preliminary:</strong> the month is not closed yet; amounts may change as late reports arrive.
        @endif
    </div>
@endsection
