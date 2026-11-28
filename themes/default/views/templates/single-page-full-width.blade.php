{{-- A page at the wide measure: as the page, with its body across the site's width. As the home page it draws the front-top and front-sections regions. --}}
@extends('theme::layouts.base')

@section('main')
    <article class="wrap page-wide">
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
