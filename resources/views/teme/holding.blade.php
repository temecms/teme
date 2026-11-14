{{-- The holding page (issue 246, I-3377): core's own, drawn before any theme, so it reads nothing but its two values. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __(':address is being set up', ['address' => $address]) }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --background: #F6F6F7;
            --card: #FFFFFF;
            --foreground: #1A1A1A;
            --muted-foreground: #5C5C5C;
            --border: #E4E4E5;
            --link: #1D4ED8;
            --ring: #0A6273;
            --warning: #8A5300;
            --sans: "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #141414;
                --card: #1C1C1D;
                --foreground: #EDEDEE;
                --muted-foreground: #A5A5A7;
                --border: #2C2C2E;
                --link: #8AB0FF;
                --ring: #48ADC2;
                --warning: #E0AE60;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            background: var(--background);
            color: var(--foreground);
            font-family: var(--sans);
            font-size: 14px;
            line-height: 1.5;
        }

        [data-holding] {
            width: 100%;
            max-width: 26rem;
            display: grid;
            gap: 20px;
            text-align: center;
        }

        .hold-addr {
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted-foreground);
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .hold-addr::before {
            content: "";
            flex: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--warning);
        }

        [data-holding] h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.25;
            font-weight: 600;
            letter-spacing: -0.015em;
            text-wrap: balance;
        }

        [data-holding] hr {
            width: 100%;
            margin: 0;
            border: 0;
            border-top: 1px solid var(--border);
        }

        .hold-owner {
            display: grid;
            gap: 6px;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--card);
            text-align: left;
            font-size: 14px;
        }

        .hold-owner p {
            margin: 0;
        }

        .hold-owner-title {
            font-weight: 600;
        }

        .hold-owner-title + p {
            color: var(--muted-foreground);
        }

        .hold-owner a {
            justify-self: start;
            padding: 2px 0;
            color: var(--link);
            font-weight: 500;
            text-underline-offset: 2px;
        }

        .hold-owner a:focus-visible {
            outline: 2px solid var(--ring);
            outline-offset: 2px;
            border-radius: 2px;
        }
    </style>
</head>
<body>
<main data-holding>
    <p class="hold-addr">{{ $address }}</p>
    <h1>{{ __('This site is being set up and will open soon.') }}</h1>
    <hr>
    <div class="hold-owner">
        <p class="hold-owner-title">{{ __('Is this your site?') }}</p>
        <p>{{ __('Finish setting it up on the setup page. You’ll need the setup code from the end of the deploy log.') }}</p>
        <a href="{{ $setupUrl }}">{{ __('Set up the site') }}</a>
    </div>
</main>
</body>
</html>
