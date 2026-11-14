@php
    // Fails on purpose for 418, so core's fallback error view must render instead.
    if ($page->code === 418) {
        throw new RuntimeException('The fixture theme\'s error view fails for 418.');
    }
@endphp
<x-teme::document>
<main id="main" data-template="error"></main>
</x-teme::document>
