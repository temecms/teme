{{-- The page for a preview link that does not open: wrong, expired or revoked, all alike, so nothing says which and nothing confirms the link once existed. --}}
@php
    $brand = app(\App\Http\Standalone\StandalonePage::class)->brand();
@endphp
<x-teme::standalone :title="__('This preview link has expired')" error="preview-expired" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Link expired') }}</p>
    <h1>{{ __('This preview link has expired') }}</h1>
    <p class="sa-quiet">{{ __('Preview links stop working after a while, so an unfinished article doesn’t stay open to anyone with the address. Ask whoever sent it to you for a new one.') }}</p>
    <a class="sa-button" href="{{ url('/') }}">{{ __('Go to the site') }}</a>
</x-teme::standalone>
