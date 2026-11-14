{{-- The featured image, when it leads the item and the owner shows it (featured_image_style), at the width they chose, with its caption and credit. The page's one priority image. --}}
@if ($page->lead !== null && $page->leadIsFeatured && ($themeSettings['featured_image_style'] ?? 'wide') !== 'hidden')
    <figure class="featured-media featured-{{ $themeSettings['featured_image_style'] ?? 'wide' }}">
        <x-teme::image :id="$page->lead->id" :context="($themeSettings['featured_image_style'] ?? 'wide') === 'full' ? 'feature' : (($themeSettings['featured_image_style'] ?? 'wide') === 'content' ? 'content' : 'wide')" :priority="true" :alt="$page->lead->alt" :decorative="$page->lead->decorative" />
        @if (($page->lead->caption ?? '') !== '' || ($page->lead->credit ?? '') !== '')
            <figcaption>{{ $page->lead->caption }}@if (($page->lead->credit ?? '') !== '') <span class="credit">{{ $page->lead->credit }}</span>@endif</figcaption>
        @endif
    </figure>
@endif
