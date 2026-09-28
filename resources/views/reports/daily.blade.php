<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daily report {{ $task->merchantMid->mid }} {{ $task->report_date->toDateString() }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p.sub { color: #6b7280; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        td.value { text-align: right; font-weight: bold; }
        tr.total td { border-top: 2px solid #111827; font-size: 13px; }
    </style>
</head>
<body>
    <h1>{{ config('app.name') }} — daily report</h1>
    <p class="sub">{{ $task->merchant->name }} · MID {{ $task->merchantMid->mid }} · {{ $task->period_from->toDateString() }} — {{ $task->period_to->toDateString() }}</p>
    <table>
        @foreach ($summary as [$label, $value])
            <tr @class(['total' => $loop->last])>
                <td>{{ $label }}</td>
                <td class="value">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
