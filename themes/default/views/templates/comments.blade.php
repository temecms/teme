{{-- A later page of an item's comments (…/comments/page/N, I-272): the story named and linked, then the thread and the form. Page 1 is the story itself. --}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap comments-page">
        <header class="article-header">
            @include('theme::partials.kicker', ['kicker' => $page->kicker])
            <h1>Comments on “{{ $page->title }}”</h1>
            <p class="meta byline"><a href="{{ $page->url }}">Read the story</a><span>Page {{ $page->comments->current }} of {{ $page->comments->total }}</span></p>
        </header>

        @include('theme::partials.comments', ['comments' => $page->comments])
    </div>
@endsection
