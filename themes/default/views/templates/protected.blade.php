{{--
    Core's page for an item protected by a password (I-1700): the title, core's line and its form. The words are core's,
    from $page, so no theme can misstate what happened; the theme lays them out. Never stored, never indexed.
--}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap comment-answer">
        <header class="article-header">
            <h1 id="page-h" tabindex="-1">{{ $page->title }}</h1>
            <p class="c-lead">{{ $page->lead() }}</p>
        </header>
        <x-teme::password-form :page="$page" />
    </div>
@endsection
