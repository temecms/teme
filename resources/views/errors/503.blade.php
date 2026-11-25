{{-- Laravel's own 503 page, maintenance among it, drawn on the one card. While a Teme update runs it names the version and how far it has come. A GET checks again by itself; a refreshed POST could answer 405. --}}
@php
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $site = $brand['name'] ?? __('this site');
    $refresh = request()->isMethod('GET') ? 20 : null;

    try {
        $update = app(\App\Domain\Updates\Queries\TemeUpdates::class)->open();
    } catch (\Throwable) {
        $update = null;
    }

    $total = count(\App\Domain\Updates\Requests\UpdateStep::cases());
    $done = 0;

    if ($update !== null) {
        foreach (\App\Domain\Updates\Requests\UpdateStep::cases() as $step) {
            if ($update->state($step) === \App\Domain\Updates\Requests\UpdateStep::DONE) {
                $done++;
            }
        }
    }

    $percent = (int) round($done / max($total, 1) * 100);
@endphp
<x-teme::standalone :title="__('Down for maintenance')" :error="503" :brand="$brand" :refresh-seconds="$refresh">
@if ($update !== null)
    <x-teme::error-pill tone="info" :dot="true">{{ __('Updating') }}</x-teme::error-pill>
@endif
    <h1>{{ __('Down for maintenance') }}</h1>
@if ($update !== null)
    <p class="sa-quiet">{{ __(':site is being updated to Teme :version. This usually takes a few minutes. Nothing you saved is affected.', ['site' => $site, 'version' => $update->to]) }}</p>
    <div>
        <div class="sa-bar" role="progressbar" aria-label="{{ __('Update progress') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><i style="width: {{ $percent }}%"></i></div>
        <p class="sa-bar-note">{{ __('Step :step of :total…', ['step' => min($done + 1, $total), 'total' => $total]) }}</p>
    </div>
@else
    <p class="sa-quiet">{{ __(':site is being updated. This usually takes a few minutes. Nothing you saved is affected.', ['site' => $site]) }}</p>
@endif
    <div class="sa-actions">
        <a class="sa-button" href="{{ request()->fullUrl() }}">{{ __('Check now') }}</a>
    </div>
@if ($refresh !== null)
    <p data-foot>{{ __('This page checks again by itself every 20 seconds.') }}</p>
@endif
</x-teme::standalone>
