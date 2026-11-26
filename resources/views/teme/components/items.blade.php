{{-- A type's items, from <x-teme::items>: the theme's own view when it names one, given $items (ListingItem), else a plain list of links. --}}
@if ($items !== [])
@if ($partial !== null)
@include('theme::'.$partial, ['items' => $items])
@else
<ul {{ $attributes->merge(['class' => "teme-items teme-items-{$type}"]) }}>
@foreach ($items as $item)
<li>@if ($item->url !== '')<a href="{{ $item->url }}">{{ $item->title }}</a>@else{{ $item->title }}@endif</li>
@endforeach
</ul>
@endif
@endif
