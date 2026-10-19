{{-- Payout calculation: $steps (see ClientDailyStatement::steps), $currency --}}
@php($n = 0)
<table class="calc avoid-break">
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
                {{ \App\Support\PdfRenderer::money($step['amount']) }}
            </td>
        </tr>
    @endforeach
</table>
