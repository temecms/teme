{{-- Core's document shell (I-247): the template's markup is the slot; everything around it is written here. The head's entries escape their own values and a fragment is written as given (I-228), so both are output raw; each placement draws its stack, this request's own pushes, before its fragments. --}}
<!doctype html>
<html lang="{{ $lang }}" data-scheme="{{ $scheme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
@foreach ($head()->meta as $tag)
    {!! $tag->html() !!}
@endforeach
@foreach ($head()->links as $link)
    {!! $link->html() !!}
@endforeach
@if ($preload !== null)
    <link rel="preload" as="font"@if ($preload['type'] !== null) type="{{ $preload['type'] }}"@endif href="{{ $preload['href'] }}" crossorigin>
@endif
    {{-- The layer order is core's (I-228), and so are the skip link's style (B-245), the Edit link's (I-163, drawn in the theme prototype), and the comment form's honeypot, off-screen rather than hidden, which some bots skip (I-578), with text only screen readers read, and the form's hidden parts, the thread's actions line and empty line, and a menu's folded lists, which core's scripts show or hide whatever a theme's display says (B-363, 1.8c″), and the Edit link's size (WCAG 2.5.8), which no theme's display may show wrongly: each keeps what it needs, !important in teme.core, whatever a theme does. --}}
    <style>@layer teme.core, teme.plugins, teme.theme;@layer teme.core{.teme-skip-link{position:absolute;inset-inline-start:.5rem;inset-block-start:-4rem;z-index:100;padding:.5rem .75rem;background:var(--teme-contrast, #111111)!important;color:var(--teme-base, #ffffff)!important;font-weight:600;text-decoration:underline}.teme-skip-link:focus{inset-block-start:.5rem}.teme-edit-link{position:fixed;inset-inline-end:calc(1rem + env(safe-area-inset-right));inset-block-end:calc(1rem + env(safe-area-inset-bottom));z-index:99;display:inline-flex;align-items:center;min-block-size:2.75rem;padding-inline:1.125rem;border:1px solid transparent;border-radius:999px;background:var(--teme-contrast, #111111)!important;color:var(--teme-base, #ffffff)!important;font:600 .9375rem/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 1px 2px rgb(0 0 0/.12),0 4px 12px rgb(0 0 0/.16)}.teme-edit-link:focus-visible{outline:2px solid var(--teme-contrast, #111111);outline-offset:2px}@media (hover:hover){.teme-edit-link:hover{text-decoration:underline}}.teme-edit-room{block-size:4.5rem}@media print{.teme-edit-link,.teme-edit-room{display:none}}.teme-hp,.teme-sr-only{position:absolute!important;inline-size:1px!important;block-size:1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important}.teme-respond [hidden]{display:none!important}[data-teme-actions][hidden],[data-teme-empty][hidden]{display:none!important}.teme-comment-edit{display:inline-flex;align-items:center;min-block-size:1.5rem;min-inline-size:1.5rem}[data-teme-submenu][hidden]{display:none!important}}</style>
@if ($tokens !== '')
    <style>{!! $tokens !!}</style>
@endif
    {!! $assets !!}
@foreach ($cardStyles() as $card)
    <link rel="stylesheet" href="{{ $card['href'] }}" data-teme-card="{{ $card['name'] }}">
@endforeach
@php($graph = $jsonLd())
@if ($graph !== null)
    <script type="application/ld+json">{!! $graph !!}</script>
@endif
    @stack('teme-head')
@foreach ($fragments('head') as $fragment)
    {!! $fragment->html !!}
@endforeach
</head>
<body>
    <a class="teme-skip-link" href="#main">{{ __('Skip to content') }}</a>
    @stack('teme-body-start')
@foreach ($fragments('body-start') as $fragment)
    {!! $fragment->html !!}
@endforeach
    {{ $slot }}
    @stack('teme-body-end')
@foreach ($fragments('body-end') as $fragment)
    {!! $fragment->html !!}
@endforeach
    {{-- Core's small script (I-163): in a browser that signed in, it asks who is reading, and adds the "Edit" link right after the skip link, outside the theme's markup, in a navigation landmark named by its own words, as WordPress's admin bar and Ghost's toolbar are, so no content sits outside a landmark (owner, 2026-09-28). The same for every reader, so the page stays shared; a browser with no marker asks nothing. It keeps its answer under `Symbol.for('teme.reader')`, so core's comments script, drawn after it on a page with a comment form (1.8b′), reads the same answer and never asks twice. --}}
    <x-teme::inline-script name="session">
    (() => {
        'use strict';
        const marked = document.cookie.split('; ').some((part) => part.startsWith(@json($marker.'=')));
        const reader = marked
            ? fetch(@json($session) + '?path=' + encodeURIComponent(location.pathname), { credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .catch(() => null)
            : Promise.resolve(null);
        document[Symbol.for('teme.reader')] = reader;
        reader
            .then((reader) => {
                const edit = reader && reader.edit;
                if (!edit || new URL(edit.href, location.href).origin !== location.origin) return;
                const link = document.createElement('a');
                link.className = 'teme-edit-link';
                link.href = edit.href;
                link.textContent = edit.label;
                const room = document.createElement('div');
                room.className = 'teme-edit-room';
                room.setAttribute('aria-hidden', 'true');
                const landmark = document.createElement('nav');
                landmark.setAttribute('aria-label', edit.label);
                landmark.append(link);
                const skip = document.querySelector('.teme-skip-link');
                if (skip) { skip.after(landmark); } else { document.body.prepend(landmark); }
                document.body.append(room);
            })
            .catch(() => {});
    })();
    </x-teme::inline-script>
    @stack('teme-core-scripts')
@if ($speculation !== null)
    <x-teme::inline-script type="speculationrules">{!! $speculation !!}</x-teme::inline-script>
@endif
</body>
</html>
