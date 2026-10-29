<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.16 0.012 265);
            }
        </style>

        @php
            $seo = config('sterling.seo');
            $isHome = request()->routeIs('home');
            $jsonLd = json_encode([
                '@context' => 'https://schema.org',
                '@graph' => [
                    ['@type' => 'Organization', '@id' => url('/').'#organization', 'name' => config('app.name'), 'url' => url('/'), 'logo' => url('/images/sterling-pay-logo.png')],
                    ['@type' => 'WebSite', '@id' => url('/').'#website', 'url' => url('/'), 'name' => config('app.name'), 'description' => $seo['description'], 'publisher' => ['@id' => url('/').'#organization'], 'inLanguage' => 'en'],
                    ['@type' => 'SoftwareApplication', 'name' => config('app.name'), 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web', 'description' => $seo['description'], 'url' => url('/')],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        @endphp
        <meta name="description" content="{{ $seo['description'] }}">
        <meta name="theme-color" content="{{ $seo['theme_color'] }}">
        <meta name="application-name" content="{{ config('app.name') }}">
        @unless ($isHome)
            {{-- The admin console, the portal and the login are not for search engines. --}}
            <meta name="robots" content="noindex, nofollow">
        @else
            <link rel="canonical" href="{{ url('/') }}">

            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:locale" content="en_GB">
            <meta property="og:url" content="{{ url('/') }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:image" content="{{ url($seo['image']) }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">

            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $seo['title'] }}">
            <meta name="twitter:description" content="{{ $seo['description'] }}">
            <meta name="twitter:image" content="{{ url($seo['image']) }}">
            <meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

            <script type="application/ld+json">{!! $jsonLd !!}</script>
        @endunless

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
