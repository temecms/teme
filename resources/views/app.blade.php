<!DOCTYPE html>
{{-- Later (I-162): dir from the admin's language, with the admin's right-to-left. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @if(($look ?? 'dark_chrome') !== 'dark_chrome') data-look="{{ $look }}" @endif @if($highContrast ?? false) data-contrast="high" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
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
                background-color: #f6f7f9;
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Teme's own icon marks the admin's tab until a site uploads its own (I-1390); a site's public pages never carry it. --}}
        <link rel="icon" href="/teme/icon.ico" sizes="any">
        <link rel="icon" href="/teme/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/teme/apple-touch-icon.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Teme') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
