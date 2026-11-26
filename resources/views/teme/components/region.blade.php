{{-- A theme's region: its published content, or nothing; what plugins place before or after it, around it, also when it's empty. A region that waits for a viewport width is a placeholder here, which core's regions script fills once, from `source`, when the viewport reaches `minWidth`, so below it nothing of the region loads. --}}
{!! $before !!}
@if ($html !== null && $minWidth !== null)
<div {{ $attributes->merge(['class' => "teme-region teme-region-{$name}"]) }} data-region="{{ $name }}" data-teme-region-min="{{ $minWidth }}" data-teme-region-src="{{ $source }}"></div>
@once
@push('teme-core-scripts')
<x-teme::inline-script name="regions">
(() => {
    'use strict';
    const fill = async (box) => {
        const page = await fetch(box.dataset.temeRegionSrc, { credentials: 'omit' });
        if (!page.ok) return;
        const html = document.createElement('template');
        html.innerHTML = await page.text();
        for (const dead of html.content.querySelectorAll('script')) {
            const live = document.createElement('script');
            for (const { name, value } of dead.attributes) live.setAttribute(name, value);
            live.text = dead.text;
            dead.replaceWith(live);
        }
        box.replaceChildren(html.content);
    };
    const start = () => {
        for (const box of document.querySelectorAll('[data-teme-region-min]')) {
            const wide = matchMedia('(min-width: ' + box.dataset.temeRegionMin + 'px)');
            const once = () => {
                if (!wide.matches) return;
                wide.removeEventListener('change', once);
                fill(box).catch(() => {});
            };
            wide.addEventListener('change', once);
            once();
        }
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
})();
</x-teme::inline-script>
@endpush
@endonce
@elseif ($html !== null)
<div {{ $attributes->merge(['class' => "teme-region teme-region-{$name}"]) }} data-region="{{ $name }}">{!! $html !!}</div>
@elseif ($undeclared)
<div data-teme-undeclared-region="{{ $name }}">The theme uses a region "{{ $name }}" that its manifest doesn't declare.</div>
@endif
{!! $after !!}
