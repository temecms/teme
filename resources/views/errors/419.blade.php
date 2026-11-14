{{-- Laravel's own 419 page, drawn on the one card for anything a theme does not answer (docs/proposals/sign-in-pages.md). --}}
@php
    $code = 419;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('This page has expired')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('This page has expired') }}</h1>
    <p class="sa-quiet">{{ __('For your safety, pages stop accepting changes after a while. Nothing you typed was saved. Go back, reload the page and try again.') }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
    <p data-foot>{{ __('Drafts in the editor are saved as you type, so they are not affected.') }}</p>
</x-teme::standalone>
