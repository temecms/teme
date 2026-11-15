{{-- The header (study Q §4): the site title, an <h1> only on the home page (I-442); the primary menu and search, drawn twice, for wide screens and in a disclosure for small ones, which CSS chooses between, with no script. --}}
<header class="site-header" data-layout="{{ $themeSettings['header_layout'] ?? 'left' }}" data-tone="{{ $themeSettings['header_color'] ?? 'page' }}">
    <div class="wrap header-inner">
        @if ($isFront)
            <h1 class="site-title">@include('theme::partials.site-name')</h1>
        @else
            <p class="site-title">@include('theme::partials.site-name')</p>
        @endif
        <div class="nav-wide"><x-teme::navigation slot="primary" :label="__('Primary')" /></div>
        <div class="search-wide">@include('theme::partials.search-form', ['id' => 'search-wide', 'value' => ''])</div>
        <details class="nav-narrow">
            <summary>{{ __('Menu') }}</summary>
            <div class="nav-narrow-body">
                <x-teme::navigation slot="primary" :label="__('Primary')" />
                @include('theme::partials.search-form', ['id' => 'search-narrow', 'value' => ''])
            </div>
        </details>
    </div>
</header>
