{{-- Laravel's own 4xx page, drawn on the one card for anything a theme does not answer. --}}
@php
    $code = $exception->getStatusCode();
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('This page can’t be shown')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('This page can’t be shown') }}</h1>
    <p class="sa-quiet">{{ __('Go back and try again, or start from Home.') }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
</x-teme::standalone>
