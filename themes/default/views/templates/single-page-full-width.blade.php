{{-- A page at the wide measure: as the page, with its body across the site's width. --}}
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

        @include('theme::partials.field-list', ['fields' => $page->shownFields('before')])

        <div class="prose">{!! $page->bodyHtml !!}</div>

        @include('theme::partials.field-list', ['fields' => $page->shownFields('after')])

        @include('theme::partials.comments', ['comments' => $page->comments])
    </article>
@endsection
