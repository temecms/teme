{{-- Laravel's own 403 page, drawn on the one card for anything a theme does not answer (docs/proposals/sign-in-pages.md). --}}
@php
    $code = 403;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $site = $brand['name'] ?? __('this site');
    $home = $page->home();
@endphp
<x-teme::standalone :title="__('You can’t open this page')" :error="$code" :brand="$brand">
    <p class="sa-pill sa-pill-warn">{{ __('Error :code', ['code' => $code]) }}</p>
    <h1>{{ __('You can’t open this page') }}</h1>
    <p class="sa-quiet">{{ __('Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role.', ['site' => $site]) }}</p>
    <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
</x-teme::standalone>
