{{-- A link to each language version of the page, unstyled; nothing on a page with no other version. --}}
@if ($links !== [])
<ul {{ $attributes }}>
@foreach ($links as $link)
<li><a href="{{ $link['url'] }}" lang="{{ $link['language'] }}" hreflang="{{ $link['language'] }}"{!! $link['current'] ? ' aria-current="page"' : '' !!}>{{ $link['label'] }}</a></li>
@endforeach
</ul>
@endif
