{{-- Core's fallback, and the floor: draws nothing from $page, so any page renders here. --}}
<x-teme::document>
<main id="main" data-fallback="index">
    <p>{{ __('This page has no template of its own.') }}</p>
</main>
</x-teme::document>
