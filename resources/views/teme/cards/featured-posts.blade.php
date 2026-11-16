{{-- Core's featured posts card (App\Domain\Cards\Types\FeaturedPostsCard), given $heading, $items (ListingItem) and $images. A theme replaces it with its own views/cards/featured-posts.blade.php. --}}
<section class="card-featured-posts">
    @if ($heading !== null)
        <h2 class="card-featured-posts-heading">{{ $heading }}</h2>
    @endif
    <ul class="card-featured-posts-list">
        @foreach ($items as $item)
            <li class="card-featured-posts-item">
                @if ($images && $item->image !== null)
                    <x-teme::image :id="$item->image->id" context="thumb" :decorative="true" class="card-featured-posts-image" />
                @endif
                @if ($item->url !== '')
                    <a class="card-featured-posts-title" href="{{ $item->url }}">{{ $item->title }}</a>
                @else
                    <span class="card-featured-posts-title">{{ $item->title }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</section>
