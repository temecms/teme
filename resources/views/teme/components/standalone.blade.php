{{-- The one card the pages outside the admin's app draw, the error pages and the holding page (docs/proposals/sign-in-pages.md): the admin's tokens as plain CSS, and nothing loaded. --}}
@props(['title', 'holding' => false, 'error' => null, 'refreshSeconds' => null, 'brand' => ['name' => null, 'mark' => null]])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
@if ($refreshSeconds !== null)
    <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
@endif
    <title>{{ $title }}</title>
    <style>{!! app(\App\Http\Standalone\StandaloneStyle::class)->tokens() !!}
        :root { color-scheme: light dark; }

        * { box-sizing: border-box; }

        .sa-page {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 16px;
            background: var(--page);
            color: var(--ink);
            font: var(--text-body)/1.5 var(--font-sans);
        }

        main {
            width: min(420px, 100%);
            display: grid;
            gap: 20px;
            padding: 32px;
            background: var(--surface);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-float);
        }

        main > * { margin: 0; }

        .sa-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sa-mark {
            flex: none;
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            background: var(--accent);
            color: var(--on-accent);
            font: 600 var(--text-lead)/1 var(--font-serif);
        }

        .sa-name {
            font-size: var(--text-body);
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        h1 {
            margin: 0;
            font-size: var(--text-title);
            font-weight: 600;
            line-height: 1.2;
            text-wrap: balance;
        }

        p { margin: 0; }

        .sa-quiet {
            color: var(--ink-2);
            font-size: var(--text-body);
        }

        [data-foot] {
            color: var(--ink-3);
            font-size: var(--text-small);
        }

        .sa-pill {
            justify-self: start;
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 10px;
            border-radius: var(--radius-full);
            font-size: var(--text-small);
            font-weight: 500;
        }

        .sa-pill-warn { background: var(--warn-soft); color: var(--warn-ink); }

        .sa-pill-danger { background: var(--danger-soft); color: var(--danger-ink); }

        .sa-pill-info { background: var(--info-soft); color: var(--info-ink); }

        .sa-notice {
            display: grid;
            gap: 8px;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            background: var(--info-soft);
            color: var(--ink);
        }

        .sa-notice > p:first-child { font-weight: 600; }

        .sa-button {
            justify-self: start;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: var(--ctl-h);
            padding: 0 16px;
            border-radius: var(--radius-sm);
            background: var(--accent);
            color: var(--on-accent);
            font-size: var(--text-body);
            font-weight: 500;
            text-decoration: none;
        }

        .sa-button:hover { background: var(--accent-hover); }

        .sa-button:focus-visible {
            outline: 2px solid var(--ring);
            outline-offset: 2px;
        }

        hr {
            width: 100%;
            height: 0;
            border: 0;
            border-top: 1px solid var(--line);
        }

        .hold-addr {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--ink-2);
            font-size: var(--text-small);
            overflow-wrap: anywhere;
        }

        .hold-addr::before {
            content: "";
            flex: none;
            width: 8px;
            height: 8px;
            border-radius: var(--radius-full);
            background: var(--warn);
        }

        .sa-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        @media (max-width: 39.99rem) {
            .sa-page {
                place-items: start center;
                padding: 20px 16px;
            }

            main { padding: 20px; }
        }
    </style>
</head>
<body class="sa-page">
@if ($holding)
<main data-holding>
@else
<main data-error="{{ $error }}">
@endif
    <div class="sa-brand">
@if ($brand['name'] !== null && $brand['mark'] !== null)
        <span class="sa-mark" aria-hidden="true">{{ $brand['mark'] }}</span>
        <span class="sa-name">{{ $brand['name'] }}</span>
@else
        {!! app(\App\Http\Standalone\StandalonePage::class)->temeMark() !!}
@endif
    </div>
    {{ $slot }}
</main>
</body>
</html>
