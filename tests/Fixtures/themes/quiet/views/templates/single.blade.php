<x-teme::document>
<main id="main" data-template="single" data-greeting="{{ $greeting }}">
    @if ($page->lead !== null && $page->leadIsFeatured)
        <x-teme::image :id="$page->lead->id" context="content" :priority="true" :alt="$page->lead->alt" />
    @endif
    {!! $page->bodyHtml !!}
</main>
</x-teme::document>
