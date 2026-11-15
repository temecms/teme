{{-- Core's fallback: drawn when no theme can be used. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="latest">
    @if ($page->feed->subject !== null)
        <h1>{{ $page->feed->subject->title }}</h1>
    @endif
    @if ($page->lead !== null)
        <article>
            <h2>
                @if ($page->lead->url !== '')
                    <a href="{{ $page->lead->url }}">{{ $page->lead->title }}</a>
                @else
                    {{ $page->lead->title }}
                @endif
            </h2>
        </article>
    @endif
    <ul>
        @foreach ($page->featured as $item)
            <li>
                @if ($item->url !== '')
                    <a href="{{ $item->url }}">{{ $item->title }}</a>
                @else
                    {{ $item->title }}
                @endif
            </li>
        @endforeach
    </ul>
    <ul>
        @foreach ($page->feed->items as $item)
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
        @if ($page->feed->pagination->previous !== null)
            <a href="{{ $page->feed->pagination->previous }}" rel="prev">{{ __('Newer') }}</a>
        @endif
        @if ($page->feed->pagination->next !== null)
            <a href="{{ $page->feed->pagination->next }}" rel="next">{{ __('Older') }}</a>
        @endif
    </nav>
</main>
</x-teme::document>
