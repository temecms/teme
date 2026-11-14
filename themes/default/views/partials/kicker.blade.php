{{-- An item's primary category, as a link, given $kicker; nothing without one. --}}
@if ($kicker !== null)
<p class="kicker"><a href="{{ $kicker->url }}">{{ $kicker->name }}</a></p>
@endif
