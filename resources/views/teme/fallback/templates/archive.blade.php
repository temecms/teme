{{-- Core's fallback: drawn when no theme can be used. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="archive">
    <h1>{{ $page->subject?->title }}</h1>
    <ul>
        @foreach ($page->items as $item)
            <li>
                @if ($item->url !== '')
                    <a href="{{ $item->url }}">{{ $item->title }}</a>
                @else
                    {{ $item->title }}
                @endif
            </li>
        @endforeach
    </ul>
    <nav>
        @if ($page->pagination->previous !== null)
            <a href="{{ $page->pagination->previous }}" rel="prev">{{ __('Newer') }}</a>
        @endif
        @if ($page->pagination->next !== null)
            <a href="{{ $page->pagination->next }}" rel="next">{{ __('Older') }}</a>
        @endif
    </nav>
</main>
</x-teme::document>
