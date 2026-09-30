<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 16mm 14mm 20mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1b1433; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #6b6880; }
        .num { text-align: right; white-space: nowrap; }
        .strong { font-weight: bold; }
        .neg { color: #b42318; }
        .pos { color: #0f766e; }

        /* Page footer */
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e6e3f0; padding-top: 2mm; font-size: 7.5px; color: #8a86a0; }

        /* Header band */
        .brand { border-bottom: 2px solid #2a1e56; padding-bottom: 10px; margin-bottom: 14px; }
        .brand .doc { text-align: right; }
        .doc-title { font-size: 17px; font-weight: bold; color: #2a1e56; letter-spacing: 0.2px; }
        .doc-sub { font-size: 9px; color: #6b6880; margin-top: 2px; }
        .pill { display: inline-block; padding: 2px 7px; border-radius: 8px; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .pill-draft { background: #fef3c7; color: #92400e; }
        .pill-approved { background: #dbeafe; color: #1e40af; }
        .pill-settled, .pill-completed { background: #d1fae5; color: #065f46; }
        .pill-cancelled { background: #fee2e2; color: #991b1b; }

        /* Parties / meta grid */
        .meta td { width: 50%; padding: 0 12px 0 0; }
        .meta .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #8a86a0; margin-bottom: 2px; }
        .meta .value { font-size: 10px; font-weight: bold; }
        .kv td { padding: 1.5px 0; }
        .kv td.k { color: #6b6880; width: 38%; }

        /* KPI tiles */
        .tiles { width: 100%; table-layout: fixed; margin: 14px 0 16px; }
        .tiles td { padding: 0 4px; }
        .tiles td:first-child { padding-left: 0; }
        .tiles td:last-child { padding-right: 0; }
        .tile { border: 1px solid #e6e3f0; border-radius: 6px; padding: 9px 6px; height: 50px; background: #faf9fe; text-align: center; }
        .tiles.tall .tile { height: 62px; }
        .tile .t-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b6880; }
        .tile .t-value { font-size: 13px; font-weight: bold; margin-top: 3px; color: #1b1433; }
        .tile .t-hint { font-size: 7.5px; color: #8a86a0; margin-top: 1px; }
        .tile.accent { background: #2a1e56; border-color: #2a1e56; }
        .tile.accent .t-label, .tile.accent .t-hint { color: #c9c3ec; }
        .tile.accent .t-value { color: #ffffff; }

        /* Sections */
        h2 { font-size: 10.5px; color: #2a1e56; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e6e3f0; }
        h2 .hint { font-weight: normal; font-size: 8px; color: #8a86a0; }

        /* Data tables */
        table.grid th { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b6880; text-align: left; padding: 5px 5px; background: #f3f1fb; border-bottom: 1px solid #ddd8ef; }
        table.grid th.num { text-align: right; }
        table.grid td { padding: 4.5px 5px; border-bottom: 1px solid #efedf6; }
        table.grid tr.sub td { background: #faf9fe; font-weight: bold; border-top: 1px solid #ddd8ef; }
        table.grid tr.total td { background: #2a1e56; color: #fff; font-weight: bold; font-size: 10px; padding: 7px 5px; }
        table.grid.compact td { padding: 3px 5px; font-size: 8px; }

        /* Calculation (waterfall) */
        table.calc td { padding: 5px 6px; border-bottom: 1px solid #efedf6; }
        table.calc td.step { width: 16px; color: #8a86a0; }
        table.calc td.detail { color: #6b6880; font-size: 8px; }
        table.calc td.amount { width: 110px; text-align: right; white-space: nowrap; }
        table.calc tr.subtotal td { background: #f3f1fb; font-weight: bold; border-bottom: 1px solid #ddd8ef; }
        table.calc tr.total td { background: #2a1e56; color: #fff; font-weight: bold; font-size: 11px; padding: 8px 6px; }
        table.calc tr.total td.detail { color: #c9c3ec; }

        .letter { color: #6e62c4; }
        .strip { margin: -6px 0 4px; border: 1px solid #e6e3f0; border-radius: 6px; }
        .strip td { padding: 6px 8px; text-align: center; font-size: 8.5px; font-weight: bold; border-left: 1px solid #efedf6; }
        .strip td:first-child { border-left: 0; }
        .strip td span { display: block; font-weight: normal; font-size: 7px; text-transform: uppercase; letter-spacing: 0.5px; color: #8a86a0; margin-bottom: 1px; }
        .txbox { margin-top: 12px; border: 1px solid #d1fae5; background: #f0fdf7; }
        .txbox td { padding: 9px 12px; }
        .txbox .t-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #047857; }
        .txbox .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5px; margin: 3px 0; word-break: break-all; color: #1b1433; }
        .txbox a { font-size: 7.5px; color: #6e62c4; }
        .txbox .amount { font-size: 13px; font-weight: bold; color: #065f46; margin-top: 2px; }
        .note { margin-top: 12px; padding: 8px 10px; background: #faf9fe; border-left: 3px solid #6e62c4; font-size: 8px; color: #4b4764; }
        .watermark { position: fixed; top: 38%; left: 8%; font-size: 90px; font-weight: bold; color: #efedf6; transform: rotate(-30deg); z-index: -1; letter-spacing: 8px; }
        .page-break { page-break-before: always; }
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="footer">
        {{ config('app.name') }} · @yield('footer')
    </div>

    @hasSection('watermark')
        <div class="watermark">@yield('watermark')</div>
    @endif

    <table class="brand">
        <tr>
            <td style="vertical-align: middle">@include('partials.pdf-logo', ['height' => 30])</td>
            <td class="doc">
                <div class="doc-title">@yield('doc-title')</div>
                <div class="doc-sub">@yield('doc-sub')</div>
            </td>
        </tr>
    </table>

    @yield('content')
</body>
</html>
