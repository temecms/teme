{{-- Every page's frame: core's document, then the site's header, the page's one <main>, and the footer. --}}
<x-teme::document>
    <div class="site">
        @include('theme::partials.site-header')
        <main id="main" class="site-main" tabindex="-1">
            @yield('main')
        </main>
        @include('theme::partials.site-footer')
    </div>
</x-teme::document>
