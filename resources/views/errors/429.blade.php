{{-- Laravel's own 429 page, drawn on the one card for anything a theme does not answer (docs/proposals/sign-in-pages.md). --}}
@php
    $code = 429;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('Too many tries')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('Too many tries') }}</h1>
    <p class="sa-quiet">{{ __('Wait a minute, then try again.') }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
</x-teme::standalone>
