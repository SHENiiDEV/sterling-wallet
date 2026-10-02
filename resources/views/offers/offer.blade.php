@php($img = fn (string $file) => 'data:'.(str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg').';base64,'.base64_encode((string) file_get_contents(public_path('images/'.$file))))
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proposal {{ $offer->number }} · {{ $offer->company_name }}</title>
    <style>
        @page { margin: 22mm 16mm 32mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1b1433; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        /* Cover: full-bleed artwork with the offer details on top. */
        .cover { position: absolute; top: -22mm; left: -16mm; width: 210mm; height: 297mm; z-index: 10; }
        .cover img.bg { position: absolute; top: 0; left: 0; width: 210mm; height: 297mm; }
        .cover .for { position: absolute; top: 158mm; left: 0; width: 210mm; text-align: center; color: #cfc8ff; font-size: 10px; letter-spacing: 2px; text-transform: uppercase; }
        .cover .company { position: absolute; top: 166mm; left: 20mm; width: 170mm; text-align: center; color: #ffffff; font-size: 22px; font-weight: bold; }
        .cover .meta { position: absolute; top: 182mm; left: 0; width: 210mm; text-align: center; color: #a9a2d6; font-size: 9px; letter-spacing: 1px; }
        .cover .bottom { position: absolute; top: 262mm; left: 0; width: 210mm; text-align: center; }
        .cover .bottom .t { color: #ffffff; font-weight: bold; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 9px; }
        .cover .bottom span { color: #e4e0ff; font-size: 9.5px; margin: 0 14px; }
        .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; background: #8e82e0; margin-right: 6px; }

        /* Footer band on every inner page. */
        .band { position: fixed; bottom: -32mm; left: -16mm; width: 210mm; height: 20mm; background: #1b1433; color: #ffffff; text-align: center; padding-top: 4.5mm; }
        .band .t { font-weight: bold; font-size: 10px; margin-bottom: 4px; }
        .band span { color: #cfc8ff; font-size: 8.5px; margin: 0 12px; }
        .ring-tr { position: fixed; top: -48mm; right: -42mm; width: 60mm; }
        .ring-bl { position: fixed; bottom: -8mm; left: -38mm; width: 58mm; }

        .page { page-break-before: always; }
        .center { text-align: center; }
        h1 { font-size: 22px; text-align: center; margin: 4mm 0 5mm; color: #1b1433; }
        h1.small { font-size: 18px; margin-top: 2mm; }
        .lead { text-align: center; font-size: 10.5px; color: #3b3558; margin: 0 8mm 7mm; }
        .lead strong { color: #1b1433; }

        .prepared { border: 1px solid #e6e3f0; border-radius: 8px; background: #faf9fe; padding: 10px 14px; margin-bottom: 8mm; }
        .prepared .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.7px; color: #8a86a0; }
        .prepared .value { font-size: 10px; font-weight: bold; margin-top: 1px; }
        .prepared .msg { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e6e3f0; color: #3b3558; white-space: pre-line; }

        .cards { border-collapse: separate; border-spacing: 8px 8px; margin: 0 -8px; width: auto; }
        .card { background: #f6f5fb; border-radius: 10px; padding: 12px 13px; width: 33.33%; height: 92px; }
        .card .icon { width: 18px; height: 5px; border-radius: 2px; background: #6e62c4; margin-bottom: 7px; }
        .card .icon.b { background: #2a1e56; width: 12px; }
        .card .name { font-size: 10.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .card .text { font-size: 8.5px; color: #4b4764; }

        /* Price tables */
        .ptable { margin-bottom: 8mm; }
        .ptable .head { background: #2a1e56; color: #ffffff; border-radius: 10px 10px 0 0; }
        .ptable .head td { padding: 11px 12px; font-weight: bold; font-size: 10.5px; letter-spacing: 0.5px; }
        .ptable .rows td { padding: 10px 12px; font-size: 10px; border-left: 1px solid #ecebf3; border-right: 1px solid #ecebf3; }
        .ptable .rows tr:nth-child(even) td { background: #f6f5fb; }
        .ptable .rows td.fee { width: 30%; text-align: center; border-left: 1px solid #2a1e56; font-size: 10.5px; }
        .ptable .foot { background: #2a1e56; height: 5mm; border-radius: 0 0 10px 10px; }
        .brand { font-weight: bold; }

        .contact { margin-top: 6mm; border-radius: 10px; background: #2a1e56; color: #ffffff; padding: 12px 16px; }
        .contact .t { font-size: 12px; font-weight: bold; margin-bottom: 3px; }
        .contact .s { color: #cfc8ff; font-size: 9px; }
        .terms { white-space: pre-line; color: #3b3558; font-size: 8.5px; }
    </style>
</head>
<body>
    <div class="band">
        <div class="t">{{ config('app.name') }} · {{ $proposal['contact']['website'] }}</div>
        @foreach ($proposal['highlights'] as $item)
            <span><span class="dot"></span>{{ $item }}</span>
        @endforeach
    </div>

    {{-- Cover --}}
    <div class="cover">
        <img class="bg" src="{{ $img('offer-cover.jpg') }}" alt="">
        <div class="for">Prepared for</div>
        <div class="company">{{ $offer->company_name }}</div>
        <div class="meta">
            {{ $offer->number }} · {{ ($offer->created_at ?? now())->format('j F Y') }}@if ($offer->valid_until) · valid until {{ $offer->valid_until->format('j F Y') }}@endif
        </div>
        <div class="bottom">
            <div class="t">{{ $proposal['headline'] }}</div>
            @foreach ($proposal['highlights'] as $item)
                <span><span class="dot"></span>{{ $item }}</span>
            @endforeach
        </div>
    </div>

    {{-- About --}}
    <div class="page">
        <div class="center" style="margin-top: 6mm">@include('partials.pdf-logo', ['height' => 44])</div>
        <h1>{{ $proposal['headline'] }}</h1>
        <p class="lead">{{ $proposal['about'] }}</p>

        <div class="prepared">
            <table>
                <tr>
                    <td style="width: 34%">
                        <div class="label">Prepared for</div>
                        <div class="value">{{ $offer->company_name }}</div>
                        @if ($offer->website)<div class="muted">{{ $offer->website }}</div>@endif
                    </td>
                    <td style="width: 33%">
                        <div class="label">Attention</div>
                        <div class="value">{{ $offer->contact_name ?: '—' }}</div>
                        @if ($offer->contact_email)<div>{{ $offer->contact_email }}</div>@endif
                    </td>
                    <td style="width: 33%">
                        <div class="label">Proposal</div>
                        <div class="value">{{ $offer->number }}</div>
                        <div>{{ ($offer->created_at ?? now())->format('j M Y') }}@if ($offer->valid_until) · valid until {{ $offer->valid_until->format('j M Y') }}@endif</div>
                    </td>
                </tr>
            </table>
            @if ($offer->intro)
                <div class="msg">{{ $offer->intro }}</div>
            @endif
        </div>

        <table class="cards">
            @foreach (array_chunk($proposal['services'], 3) as $row)
                <tr>
                    @foreach ($row as [$name, $text])
                        <td class="card">
                            <div class="icon @if ($loop->even) b @endif"></div>
                            <div class="name">{{ $name }}</div>
                            <div class="text">{{ $text }}</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    {{-- Pricing --}}
    <div class="page">
        <img class="ring-tr" src="{{ $img('offer-ring.png') }}" alt="">
        <h1>Card Acquiring</h1>

        <div class="ptable">
            <table class="head"><tr><td></td><td style="width: 30%; text-align: center">FEE</td></tr></table>
            <table class="rows">
                @foreach ($acquiring as [$brand, $label, $fee])
                    <tr>
                        <td>@if ($brand)<span class="brand">{{ $brand }} //</span> @endif{{ $label }}</td>
                        <td class="fee">{{ $fee }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="foot"></div>
        </div>
    </div>

    {{-- Settlement and terms --}}
    <div class="page">
        <img class="ring-bl" src="{{ $img('offer-ring.png') }}" alt="">
        <h1>Settlement</h1>

        <div class="ptable">
            <table class="head"><tr><td></td><td style="width: 30%; text-align: center">TERMS</td></tr></table>
            <table class="rows">
                @foreach ($settlement as [$label, $value])
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="fee">{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="foot"></div>
        </div>

        @if ($offer->terms)
            <h1 class="small">Terms</h1>
            <div class="terms">{{ $offer->terms }}</div>
        @endif

        <div class="contact">
            <div class="t">Ready to start?</div>
            <div class="s">Reply to this proposal or write to {{ $proposal['contact']['email'] }} — onboarding usually takes a few business days.</div>
        </div>
    </div>
</body>
</html>
