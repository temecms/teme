{{-- Related posts, given $related, each with the reason it's related as a small label. --}}
<section class="related" aria-labelledby="related-title">
    <h2 class="section-title" id="related-title">Related</h2>
    <div class="feed-grid">
        @foreach ($related as $entry)
            <div class="related-item">
                <p class="reason">{{ $entry['reason'] }}</p>
                @include('theme::partials.card', ['item' => $entry['item'], 'context' => 'card', 'showImage' => true, 'showMeta' => false, 'level' => 3])
            </div>
        @endforeach
    </div>
</section>
