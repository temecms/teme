{{-- Laravel's own 404 page, drawn on the one card for anything a theme does not answer; inside the admin, AdminErrorPage draws it in the admin instead. --}}
@php
    $code = 404;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
    $back = url()->previous();
    $back = $back === request()->fullUrl() || $back === request()->url() ? null : $back;
@endphp
<x-teme::standalone :title="__('We can’t find that page')" :error="$code" :brand="$brand">
    <x-teme::error-pill tone="warn">{{ __('Error :code', ['code' => $code]) }}</x-teme::error-pill>
    <h1>{{ __('We can’t find that page') }}</h1>
    <p class="sa-quiet">{{ __('The address may be mistyped, or the item may have moved to the Trash.') }}</p>
    <div class="sa-actions">
        <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
@if ($back !== null)
        <a class="sa-button sa-button-neutral" href="{{ $back }}">{{ __('Go back') }}</a>
@endif
    </div>
</x-teme::standalone>
