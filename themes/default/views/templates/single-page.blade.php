{{-- A page: its title, its featured image, and its body; no byline, tags, author box or related posts. As the home page, its title is an <h2>: the site title is the page's <h1> (I-442), and it draws the front-top and front-sections regions. --}}
@extends('theme::layouts.base')

@section('main')
    <article class="wrap">
        <div class="page-header">
            @if ($isFront)
                <h2>{{ $page->title }}</h2>
            @else
                <h1>{{ $page->title }}</h1>
            @endif
        </div>

        @include('theme::partials.featured-media')

        @if ($isFront)
            <x-teme::region name="front-top" />
        @endif

        @include('theme::partials.field-list', ['fields' => $page->shownFields('before')])

        <div class="prose">{!! $page->bodyHtml !!}</div>

        @include('theme::partials.field-list', ['fields' => $page->shownFields('after')])

        @if ($isFront)
            <x-teme::region name="front-sections" />
        @endif

        @include('theme::partials.comments', ['comments' => $page->comments])
    </article>
@endsection
