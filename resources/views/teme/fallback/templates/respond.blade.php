{{-- Core's own pages about writing a comment: the reply page, and the answer to a form posted without JavaScript, drawn when the theme has no templates.respond, never the theme's index. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="respond">
    <h1 id="page-h" tabindex="-1">{{ $page->heading }}</h1>
    {{-- Core built each lead line and escaped its words. --}}
    @foreach ($page->lead as $line)
        <p>{!! $line !!}</p>
    @endforeach
    @if ($page->hint !== null)
        <p>{{ $page->hint }}</p>
    @endif
    @if ($page->context !== null)
        <div>
            <p><strong>{{ $page->context->name }}</strong> <time datetime="{{ $page->context->iso }}">{{ $page->context->dateText }}</time></p>
            {!! $page->context->bodyHtml !!}
        </div>
    @endif
    @if ($page->echoHtml !== null)
        <h2>{{ __('What you wrote') }}</h2>
        {!! $page->echoHtml !!}
    @endif
    @if ($page->keep !== null)
        <p><label for="c-keep">{{ __('Your comment') }}</label></p>
        <textarea id="c-keep" rows="6" readonly>{{ $page->keep }}</textarea>
    @endif
    @if ($page->form !== null)
        <x-teme::comment-form :form="$page->form" />
    @endif
    <ul>
        @foreach ($page->links as $link)
            <li><a href="{{ $link['href'] }}">{{ $link['text'] }}</a></li>
        @endforeach
    </ul>
</main>
</x-teme::document>
