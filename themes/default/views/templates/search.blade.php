{{--
    The search page (study Q §4), at /?s= and /page/N/?s=: the heading, the form prefilled, how many were found, and
    each result as a card with its snippet in place of the standfirst, then pages. A blank query shows the form alone.
--}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap search-page">
        <header class="archive-header">
            <h1>{{ $page->subject?->title }}</h1>
        </header>

        @include('theme::partials.search-form', ['id' => 'search-results', 'value' => $page->search?->query ?? ''])

        @if ($page->search === null || $page->search->empty)
            <p class="empty">{{ __('Type a word or two to search the site.') }}</p>
        @elseif ($page->search->results === [])
            <div class="empty" role="status"><p>{{ __('Nothing matched “:query”. Try fewer words, or check the spelling.', ['query' => $page->search->query]) }}</p></div>
        @else
            <p class="meta search-count" role="status">{{ $page->search->totalCapped ? __('More than :count results', ['count' => \Illuminate\Support\Number::format($page->search->total, locale: app()->getLocale())]) : ($page->search->total === 1 ? __('1 result') : __(':count results', ['count' => \Illuminate\Support\Number::format($page->search->total, locale: app()->getLocale())])) }}</p>

            <div class="feed-list">
                @foreach ($page->search->results as $result)
                    @include('theme::partials.card', ['item' => $result->item, 'context' => 'thumb', 'showImage' => $themeSettings['feed_images'] ?? true, 'showMeta' => true, 'snippetHtml' => $result->snippetHtml])
                @endforeach
            </div>

            @include('theme::partials.pagination', ['pagination' => $page->pagination])
        @endif
    </div>
@endsection
