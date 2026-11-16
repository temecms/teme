{{-- About each of an article's authors who has written a bio, given $authors. --}}
@foreach ($authors as $author)
    @if (($author->bio ?? '') !== '')
        <section class="author-box" aria-labelledby="author-{{ $loop->index }}">
            <h2 id="author-{{ $loop->index }}">{{ __('About :name', ['name' => $author->name]) }}</h2>
            <p>{{ $author->bio }}</p>
            @if ($author->url !== '')
                <p class="meta"><a href="{{ $author->url }}">{{ __('More from :name', ['name' => $author->name]) }}</a></p>
            @endif
        </section>
    @endif
@endforeach
