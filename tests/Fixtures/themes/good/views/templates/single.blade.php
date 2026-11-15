<x-teme::document>
<x-teme::navigation slot="primary" label="Main" />
<main id="main" data-template="single" data-greeting="{{ $greeting }}">
    @if ($page->lead !== null && $page->leadIsFeatured)
        <x-teme::image :id="$page->lead->id" context="content" :priority="true" :alt="$page->lead->alt" />
    @endif
    <h1>{{ $page->title }}</h1>
    {!! $page->bodyHtml !!}
    <x-teme::region name="after-content" />
</main>
</x-teme::document>
