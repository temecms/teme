{{--
    Every archive, titled by its subject (study Q §4): a category or tag by its name, an author by theirs, a date as
    written. The full header adds the description and, once a subject has one, its image.
--}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap">
        <header class="archive-header">
            <h1>{{ $page->subject?->title }}</h1>
            @if ($page->introHtml !== null)
                <div class="archive-intro">{!! $page->introHtml !!}</div>
            @endif
            @if (($themeSettings['archive_header'] ?? 'full') === 'full' && ($page->subject?->description ?? '') !== '')
                <p class="description">{{ $page->subject->description }}</p>
            @endif
            @if (($themeSettings['archive_header'] ?? 'full') === 'full' && $page->subject?->image !== null)
                <div class="archive-media"><x-teme::image :id="$page->subject->image->id" context="lead" :decorative="true" /></div>
            @endif
        </header>

        @if ($page->items === [])
            <div class="empty"><p>{{ __('Nothing here yet.') }}</p></div>
        @else
            <div class="{{ ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'feed-grid' : 'feed-list' }}">
                @foreach ($page->items as $item)
                    @include('theme::partials.card', ['item' => $item, 'context' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'card' : 'thumb', 'showImage' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' || ($themeSettings['feed_images'] ?? true), 'showMeta' => $themeSettings['show_card_meta'] ?? true])
                    <x-teme::between-items :after="$loop->iteration" :last="$loop->last" />
                @endforeach
            </div>
        @endif

        @include('theme::partials.pagination', ['pagination' => $page->pagination])
    </div>
@endsection
