{{-- The default theme's featured posts card, given $heading, $items and $images: each item drawn as the theme's own small card. --}}
<section class="card-featured-posts">
    @if ($heading !== null)
        <h2 class="card-featured-posts-heading">{{ $heading }}</h2>
    @endif
    <div class="card-featured-posts-list">
        @foreach ($items as $item)
            @include('theme::partials.card', ['item' => $item, 'context' => 'thumb', 'showImage' => $images, 'showMeta' => false, 'level' => 3])
        @endforeach
    </div>
</section>
