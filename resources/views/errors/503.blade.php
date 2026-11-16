{{-- Laravel's own 503 page, maintenance among it, drawn on the one card. A GET checks again by itself; a refreshed POST could answer 405. --}}
@php
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $site = $brand['name'] ?? __('this site');
    $home = $page->home();
    $refresh = request()->isMethod('GET') ? 20 : null;
@endphp
<x-teme::standalone :title="__('Down for maintenance')" :error="503" :brand="$brand" :refresh-seconds="$refresh">
    <p class="sa-pill sa-pill-info">{{ __('Maintenance') }}</p>
    <h1>{{ __('Down for maintenance') }}</h1>
    <p class="sa-quiet">{{ __(':site is being updated. This usually takes a few minutes. Nothing you saved is affected.', ['site' => $site]) }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
@if ($refresh !== null)
    <p data-foot>{{ __('This page checks again by itself every 20 seconds.') }}</p>
@endif
</x-teme::standalone>
