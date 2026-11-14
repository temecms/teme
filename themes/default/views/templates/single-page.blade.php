{{-- A page: its title, its featured image, and its body; no byline, tags, author box or related posts. As the home page, its title is an <h2>: the site title is the page's <h1> (I-442). --}}
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

        @include('theme::partials.field-list', ['fields' => $page->shownFields('before')])

        <div class="prose">{!! $page->bodyHtml !!}</div>

        @include('theme::partials.field-list', ['fields' => $page->shownFields('after')])

        @include('theme::partials.comments', ['comments' => $page->comments])
    </article>
@endsection
