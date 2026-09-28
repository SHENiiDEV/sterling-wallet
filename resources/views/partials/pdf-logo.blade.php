@php($logo = public_path('images/sterling-pay-logo.png'))
@if (is_file($logo))
    <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logo)) }}" alt="Sterling Pay" style="height: {{ $height ?? 28 }}px">
@endif
