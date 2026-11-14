{{-- Core's fallback: drawn when no theme can be used. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="single">
    <article>
        <h1>{{ $page->title }}</h1>
        {!! $page->bodyHtml !!}
    </article>
</main>
</x-teme::document>
