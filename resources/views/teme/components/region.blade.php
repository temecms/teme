{{-- A theme's region: its published content, or nothing; what plugins place before or after it, around it, also when it's empty. --}}
{!! $before !!}
@if ($html !== null)
<div {{ $attributes->merge(['class' => "teme-region teme-region-{$name}"]) }} data-region="{{ $name }}">{!! $html !!}</div>
@elseif ($undeclared)
<div data-teme-undeclared-region="{{ $name }}">The theme uses a region "{{ $name }}" that its manifest doesn't declare.</div>
@endif
{!! $after !!}
