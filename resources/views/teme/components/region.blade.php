{{-- A theme's region (study B §3e): its published content, or nothing. --}}
@if ($html !== null)
<div {{ $attributes->merge(['class' => "teme-region teme-region-{$name}"]) }} data-region="{{ $name }}">{!! $html !!}</div>
@elseif ($undeclared)
<div data-teme-undeclared-region="{{ $name }}">The theme uses a region "{{ $name }}" that its manifest doesn't declare.</div>
@endif
