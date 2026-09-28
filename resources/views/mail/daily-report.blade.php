<p><img src="{{ asset('images/sterling-pay-logo.png') }}" alt="Sterling Pay" height="32" style="height:32px"></p>
<p>Hello,</p>
<p>
    Attached is the daily report for MID <strong>{{ $task->merchantMid->mid }}</strong>
    ({{ $task->period_from->toDateString() }} — {{ $task->period_to->toDateString() }}).
</p>
<p>Payout: <strong>{{ number_format((float) $task->net_payout, 2) }} {{ $task->currency }}</strong></p>
<p>— {{ config('app.name') }}</p>
