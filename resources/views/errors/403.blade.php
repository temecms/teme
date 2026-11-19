{{-- Laravel's own 403 page, drawn on the one card for anything a theme does not answer; inside the admin, AdminErrorPage draws it in the admin instead. --}}
@php
    $code = 403;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $site = $brand['name'] ?? __('this site');
    $home = $page->home();
    $back = url()->previous();
    $back = $back === request()->fullUrl() || $back === request()->url() ? null : $back;
    $name = request()->hasSession() ? request()->user()?->name : null;
    // A refusal that says why (a policy's own reason) says it in place of the general words.
    $reason = trim((string) $exception->getMessage());
    $reason = in_array($reason, ['', 'This action is unauthorized.', __('This action is unauthorized.')], true) ? null : $reason;
@endphp
<x-teme::standalone :title="__('You can’t open this page')" :error="$code" :brand="$brand">
    <x-teme::error-pill tone="warn">{{ __('Error :code', ['code' => $code]) }}</x-teme::error-pill>
    <h1>{{ __('You can’t open this page') }}</h1>
    <p class="sa-quiet">{{ $reason ?? __('Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role.', ['site' => $site]) }}</p>
    <div class="sa-actions">
        <a class="sa-button" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
@if ($back !== null)
        <a class="sa-button sa-button-neutral" href="{{ $back }}">{{ __('Go back') }}</a>
@endif
    </div>
@if (is_string($name) && $name !== '')
    <p data-foot>{{ __('Signed in as :name.', ['name' => $name]) }}</p>
@endif
</x-teme::standalone>
