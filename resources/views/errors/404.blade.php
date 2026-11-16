{{-- Laravel's own 404 page, drawn on the one card for anything a theme does not answer. --}}
@php
    $code = 404;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('We can’t find that page')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('We can’t find that page') }}</h1>
    <p class="sa-quiet">{{ __('The address may be mistyped, or the item may have moved to the Trash.') }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
</x-teme::standalone>
