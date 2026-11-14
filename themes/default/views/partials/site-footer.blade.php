{{-- The footer (study Q §4): the footer menu, the site's name and tagline, and the copyright, in the header's colour. No "Powered by". --}}
<footer class="site-footer" data-tone="{{ $themeSettings['header_color'] ?? 'page' }}">
    <div class="wrap footer-inner">
        <p class="footer-id"><strong>{{ $site->name }}</strong>@if ($site->tagline !== '') {{ $site->tagline }}@endif</p>
        <x-teme::navigation slot="footer" label="Footer" />
        <p class="copyright">© {{ $site->year }} {{ $site->name }}</p>
    </div>
</footer>
