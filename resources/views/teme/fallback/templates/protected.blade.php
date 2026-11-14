{{-- Core's own page for an item protected by a password (I-1700), drawn when the theme has no templates.protected, never the theme's index. Reads only $page. --}}
<x-teme::document>
<main id="main" data-fallback="protected">
    <h1 id="page-h" tabindex="-1">{{ $page->title }}</h1>
    <p>{{ $page->lead() }}</p>
    <x-teme::password-form :page="$page" />
</main>
</x-teme::document>
