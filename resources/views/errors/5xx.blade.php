{{-- Laravel's own 5xx page, drawn on the one card for anything a theme does not answer (docs/proposals/sign-in-pages.md). --}}
@php
    $code = $exception->getStatusCode();
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('Something went wrong on our side')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-danger">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('Something went wrong on our side') }}</h1>
    <p class="sa-quiet">{{ __('Teme couldn’t finish that. Drafts you were editing are saved as you type, so nothing is lost. The problem has been written to the log.') }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
    <p data-foot>{{ __('If it keeps happening, tell whoever runs the site.') }}</p>
</x-teme::standalone>
