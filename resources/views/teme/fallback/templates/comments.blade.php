{{-- Core's own later page of an item's comments, drawn when the theme has no templates.comments, never the theme's index. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="comments">
    <h1>{{ __('Comments on “:title”', ['title' => $page->title]) }}</h1>
    <p><a href="{{ $page->url }}">{{ __('Read the story') }}</a></p>
    <p>{{ __('Page :current of :total', ['current' => $page->comments->current, 'total' => $page->comments->total]) }}</p>
    <h2>{{ __('Comments') }}</h2>
    <ol>
        @foreach ($page->comments->items as $comment)
            @include('teme::fallback.partials.comment', ['comment' => $comment])
        @endforeach
    </ol>
    <nav aria-label="{{ __('Pages of comments') }}">
        @if ($page->comments->pagination->previous !== null)
            <a href="{{ $page->comments->pagination->previous }}" rel="prev">{{ __('Older comments') }}</a>
        @endif
        @if ($page->comments->pagination->next !== null)
            <a href="{{ $page->comments->pagination->next }}" rel="next">{{ __('Newer comments') }}</a>
        @endif
    </nav>
</main>
</x-teme::document>
