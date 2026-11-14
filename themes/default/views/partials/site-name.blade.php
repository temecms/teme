{{-- The site's name as a link home, with its tagline. --}}
<a href="{{ $site->url }}"><span>{{ $site->name }}</span>@if ($site->tagline !== '')<span class="site-tagline">{{ $site->tagline }}</span>@endif</a>
