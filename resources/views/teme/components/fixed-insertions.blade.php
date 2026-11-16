{{-- What plugins fix to the screen's edges (docs/plans/plugin-sockets-2.md, B2), drawn by core at the end of the body: one per edge, its plugin's label, a close button that keeps it closed for the tab's session, no animation. A bar at the top or bottom is at most min(30vh, 250px) high, and the page is padded by its height so nothing is covered at the end; a rail shows only on a screen with room beside the content. --}}
<style>@layer teme.core{.teme-fixed{position:fixed;z-index:98;box-sizing:border-box;display:flex;flex-direction:column;gap:.25rem;padding:.5rem .75rem;overflow:hidden;background:var(--teme-base, #ffffff);color:var(--teme-contrast, #111111);border:1px solid color-mix(in srgb, var(--teme-contrast, #111111) 20%, transparent)}.teme-fixed[data-edge="bottom"],.teme-fixed[data-edge="top"]{inset-inline:0;max-block-size:min(30vh,250px);border-inline:0}.teme-fixed[data-edge="bottom"]{inset-block-end:0;border-block-end:0;padding-block-end:calc(.5rem + env(safe-area-inset-bottom))}.teme-fixed[data-edge="top"]{inset-block-start:0;border-block-start:0}.teme-fixed[data-edge="left"],.teme-fixed[data-edge="right"]{inset-block-start:50%;translate:0 -50%;max-block-size:calc(100vh - 2rem);inline-size:calc(var(--teme-rail) + 1.5rem)}.teme-fixed[data-edge="left"]{inset-inline-start:1rem}.teme-fixed[data-edge="right"]{inset-inline-end:1rem}.teme-fixed-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;font:500 .75rem/1.2 system-ui,sans-serif}.teme-fixed-close{display:inline-flex;align-items:center;justify-content:center;min-inline-size:1.5rem;min-block-size:1.5rem;padding:0;border:0;border-radius:999px;background:transparent;color:inherit;cursor:pointer}.teme-fixed-close:focus-visible{outline:2px solid currentColor;outline-offset:2px}.teme-fixed-body{overflow:hidden;min-block-size:0}@media print{.teme-fixed{display:none!important}}}</style>
@foreach ($fixed as $item)
@if ($item['minWidth'] > 0)
<style>@layer teme.core{@media (max-width:{{ $item['minWidth'] - 1 }}px){.teme-fixed[data-teme-fixed="{{ $item['key'] }}"]{display:none!important}}}</style>
@endif
@endforeach
@foreach ($fixed as $item)
<aside class="teme-fixed" data-edge="{{ $item['edge'] }}" data-teme-fixed="{{ $item['key'] }}" aria-label="{{ $item['label'] }}"@if ($item['width'] > 0) style="--teme-rail:{{ $item['width'] }}px"@endif>
    <div class="teme-fixed-head"><span>{{ $item['label'] }}</span><button type="button" class="teme-fixed-close" data-teme-fixed-close aria-label="{{ __('Close') }}"><svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>
    <div class="teme-fixed-body">{!! $item['html'] !!}</div>
</aside>
@endforeach
<x-teme::inline-script name="fixed-insertions">
(() => {
    'use strict';
    const store = (key, set) => { try { return set ? (sessionStorage.setItem('teme.fixed.' + key, '1'), true) : sessionStorage.getItem('teme.fixed.' + key) === '1'; } catch { return false; } };
    const pad = () => {
        const style = document.body.style;
        style.paddingBlockEnd = '';
        style.paddingBlockStart = '';
        for (const bar of document.querySelectorAll('.teme-fixed[data-edge="bottom"], .teme-fixed[data-edge="top"]')) {
            const height = bar.getBoundingClientRect().height + 'px';
            if (bar.dataset.edge === 'bottom') { style.paddingBlockEnd = height; } else { style.paddingBlockStart = height; }
        }
    };
    for (const aside of document.querySelectorAll('.teme-fixed')) {
        const key = aside.getAttribute('data-teme-fixed') || '';
        if (store(key, false)) { aside.remove(); continue; }
        const close = aside.querySelector('[data-teme-fixed-close]');
        if (close) { close.addEventListener('click', () => { store(key, true); aside.remove(); pad(); }); }
    }
    pad();
    addEventListener('resize', pad);
})();
</x-teme::inline-script>
