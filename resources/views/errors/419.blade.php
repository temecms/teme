{{-- Laravel's own 419 page, drawn on the one card for anything a theme does not answer. Reloading opens the page the form was on again. --}}
@php
    $code = 419;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
    $reload = request()->isMethod('GET') ? request()->fullUrl() : url()->previous();
@endphp
<x-teme::standalone :title="__('This page has expired')" :error="$code" :brand="$brand">
    <x-teme::error-pill tone="warn">{{ __('Error :code', ['code' => $code]) }}</x-teme::error-pill>
    <h1>{{ __('This page has expired') }}</h1>
    <p class="sa-quiet">{{ __('For your safety, pages stop accepting changes after a while. Nothing you typed was saved. Reload the page and try again.') }}</p>
    <div class="sa-actions">
        <a class="sa-button" href="{{ $reload }}">{{ __('Reload the page') }}</a>
        <a class="sa-button sa-button-neutral" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
    </div>
    <p data-foot>{{ __('Drafts in the editor are saved as you type, so they are not affected.') }}</p>
</x-teme::standalone>
