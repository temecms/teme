{{--
    Core's pages about writing a comment (I-593, I-594): the reply page without JavaScript, and the answer to a form posted
    without it. Their words are core's, from $page, so no theme can misstate what happened; the theme lays them out. Never
    stored, never indexed.
--}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap comment-answer">
        <header class="article-header">
            <p class="kicker">{{ __('Comments') }}</p>
            <h1 id="page-h" tabindex="-1">{{ $page->heading }}</h1>
            {{-- Core built each lead line and escaped its words. --}}
            @foreach ($page->lead as $line)
                <p class="c-lead">{!! $line !!}</p>
            @endforeach
            @if ($page->hint !== null)
                <p class="teme-hint">{{ $page->hint }}</p>
            @endif
        </header>
        @if ($page->context !== null)
            <div class="c-context">@include('theme::partials.comment-card', ['comment' => $page->context, 'withReply' => false])</div>
        @endif
        @if ($page->echoHtml !== null)
            <section class="c-echo" aria-labelledby="echo-h"><h2 id="echo-h">{{ __('What you wrote') }}</h2><div class="teme-comment-body">{!! $page->echoHtml !!}</div></section>
        @endif
        @if ($page->keep !== null)
            <div class="teme-field"><label for="c-keep">{{ __('Your comment') }}</label><textarea id="c-keep" rows="6" readonly>{{ $page->keep }}</textarea></div>
        @endif
        @if ($page->form !== null)
            <x-teme::comment-form :form="$page->form" />
        @endif
        <ul class="c-next">
            @foreach ($page->links as $link)
                <li><a href="{{ $link['href'] }}">{{ $link['text'] }}</a></li>
            @endforeach
        </ul>
    </div>
@endsection
