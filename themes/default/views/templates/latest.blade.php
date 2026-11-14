{{--
    The latest posts (I-255, I-442), at the site's address or at the posts page's. With the Lead story header,
    page 1 opens with the lead, its image the page's one priority image, then the pinned posts as a row. With None,
    the lead and the pinned posts open the feed instead, since core always leaves them out of it; with the row
    turned off, the pinned posts open the feed. Later pages hold the feed alone.
--}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap">
        @if (! $isFront && $page->feed->subject !== null)
            <h1 class="page-title">{{ $page->feed->subject->title }}</h1>
        @endif

        @if ($page->lead === null && $page->featured === [] && $page->feed->items === [])
            <div class="empty"><p>Nothing published yet.</p></div>
        @else
            @if (($themeSettings['latest_header'] ?? 'lead') === 'lead' && $page->lead !== null)
                @include('theme::partials.card', ['item' => $page->lead, 'context' => 'lead', 'showImage' => true, 'showMeta' => true, 'priority' => true, 'class' => 'lead'])

                @if (($themeSettings['show_featured'] ?? true) && $page->featured !== [])
                    <div class="featured">
                        @foreach ($page->featured as $item)
                            @include('theme::partials.card', ['item' => $item, 'context' => 'card', 'showImage' => true, 'showMeta' => false])
                        @endforeach
                    </div>
                @endif
            @endif

            <div class="{{ ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'feed-grid' : 'feed-list' }}">
                @if (($themeSettings['latest_header'] ?? 'lead') === 'none' && $page->lead !== null)
                    @include('theme::partials.card', ['item' => $page->lead, 'context' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'card' : 'thumb', 'showImage' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' || ($themeSettings['feed_images'] ?? true), 'showMeta' => true, 'priority' => true])
                @endif
                @if (($themeSettings['latest_header'] ?? 'lead') === 'none' || ! ($themeSettings['show_featured'] ?? true))
                    @foreach ($page->featured as $item)
                        @include('theme::partials.card', ['item' => $item, 'context' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'card' : 'thumb', 'showImage' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' || ($themeSettings['feed_images'] ?? true), 'showMeta' => true])
                    @endforeach
                @endif
                @foreach ($page->feed->items as $item)
                    @include('theme::partials.card', ['item' => $item, 'context' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' ? 'card' : 'thumb', 'showImage' => ($themeSettings['feed_layout'] ?? 'list') === 'grid' || ($themeSettings['feed_images'] ?? true), 'showMeta' => true])
                @endforeach
            </div>

            @include('theme::partials.pagination', ['pagination' => $page->feed->pagination])
        @endif
    </div>
@endsection
