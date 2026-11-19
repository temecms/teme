{{-- Laravel's own 500 page, drawn on the one card for anything a theme does not answer, with the reference the log holds for this failure. --}}
@php
    $code = 500;
    $page = app(\App\Http\Standalone\StandalonePage::class);
    $brand = $page->brand();
    $home = $page->home();
    $reference = app(\App\Http\Standalone\ErrorReference::class)->code();
    $again = request()->isMethod('GET') ? request()->fullUrl() : url()->previous();
@endphp
<x-teme::standalone :title="__('Something went wrong on our side')" :error="$code" :brand="$brand">
    <x-teme::error-pill tone="danger">{{ __('Error :code', ['code' => $code]) }}</x-teme::error-pill>
    <h1>{{ __('Something went wrong on our side') }}</h1>
    <p class="sa-quiet">{{ __('Teme couldn’t finish that. Drafts you were editing are saved as you type, so nothing is lost. The problem has been written to the log.') }}</p>
    <dl class="sa-ref">
        <dt>{{ __('Reference') }}</dt>
        <dd><code data-reference>{{ $reference }}</code></dd>
    </dl>
    <div class="sa-actions">
        <a class="sa-button" href="{{ $again }}">{{ __('Try again') }}</a>
        <a class="sa-button sa-button-neutral" href="{{ $home['url'] }}">{{ $home['label'] }}</a>
    </div>
    <p data-foot>{{ __('If it keeps happening, tell whoever runs the site and give them the reference.') }}</p>
</x-teme::standalone>
