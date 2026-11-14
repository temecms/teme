{{-- Core's fallback: drawn when no theme can be used. A search: the form, how many were found, each result's title and snippet, and pages. --}}
<x-teme::document>
<main id="main" data-fallback="search">
    <h1>{{ $page->subject?->title }}</h1>
    <form role="search" action="{{ $site->url }}" method="get">
        <label for="search-results">Search</label>
        <input id="search-results" name="s" type="search" value="{{ $page->search?->query ?? '' }}">
        <button type="submit">Search</button>
    </form>
    @if ($page->search === null || $page->search->empty)
        <p>Type a word or two to search the site.</p>
    @elseif ($page->search->results === [])
        <p>Nothing matched “{{ $page->search->query }}”.</p>
    @else
        <p>{{ $page->search->totalCapped ? 'More than '.$page->search->total : $page->search->total }} {{ $page->search->total === 1 && ! $page->search->totalCapped ? 'result' : 'results' }}</p>
        <ul>
            @foreach ($page->search->results as $result)
                <li>
                    @if ($result->item->url !== '')
                        <a href="{{ $result->item->url }}">{{ $result->item->title }}</a>
                    @else
                        {{ $result->item->title }}
                    @endif
                    {{-- The one raw output: the snippet was escaped before its matches were marked (App\Domain\Search\Snippet). --}}
                    <p>{!! $result->snippetHtml !!}</p>
                </li>
            @endforeach
        </ul>
    @endif
    <nav>
        @if ($page->pagination->previous !== null)
            <a href="{{ $page->pagination->previous }}" rel="prev">Newer</a>
        @endif
        @if ($page->pagination->next !== null)
            <a href="{{ $page->pagination->next }}" rel="next">Older</a>
        @endif
    </nav>
</main>
</x-teme::document>
