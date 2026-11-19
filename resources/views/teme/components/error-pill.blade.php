{{-- The error card's pill, with its mark: a warning triangle, or a dot while the site is being updated. --}}
@props(['tone' => 'warn', 'dot' => false])
<p class="sa-pill sa-pill-{{ $tone }}">
@if ($dot)
    <span class="sa-dot" aria-hidden="true"></span>
@else
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
@endif
    {{ $slot }}
</p>
