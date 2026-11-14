{{--
    An article, in study Q §4's order: kicker, title, standfirst, byline, featured image, body, tags, author box,
    the after-content region, comments, related posts. Each part is left out when its data is missing, or when the
    owner turns it off.
--}}
@extends('theme::layouts.base')

@section('main')
    <article class="wrap">
        <header class="article-header">
            @include('theme::partials.kicker', ['kicker' => $page->kicker])
            @if ($isFront)
                <h2>{{ $page->title }}</h2>
            @else
                <h1>{{ $page->title }}</h1>
            @endif
            @if ($page->standfirst !== null && $page->standfirst !== '')
                <p class="standfirst">{{ $page->standfirst }}</p>
            @endif
            <p class="meta byline">@include('theme::partials.byline', ['authors' => $page->authors, 'published' => $page->published, 'publishedText' => $page->publishedText, 'updated' => $page->updated, 'updatedText' => $page->updatedText, 'readingMinutes' => ($themeSettings['show_reading_time'] ?? true) ? $page->readingMinutes : null])</p>
        </header>

        @include('theme::partials.featured-media')

        @include('theme::partials.field-list', ['fields' => $page->shownFields('before')])

        <div class="prose">{!! $page->bodyHtml !!}</div>

        @include('theme::partials.field-list', ['fields' => $page->shownFields('after')])

        @include('theme::partials.tags', ['tags' => $page->tags])

        @if ($themeSettings['show_author_box'] ?? true)
            @include('theme::partials.author-box', ['authors' => $page->authors])
        @endif

        <x-teme::region name="after-content" />

        @include('theme::partials.comments', ['comments' => $page->comments])
    </article>

    @if (($themeSettings['show_related'] ?? true) && $page->related !== [])
        <div class="wrap">
            @include('theme::partials.related', ['related' => $page->related])
        </div>
    @endif
@endsection
