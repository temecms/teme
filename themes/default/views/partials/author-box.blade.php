{{-- About each of an article's authors who has written a bio, given $authors. --}}
@foreach ($authors as $author)
    @if (($author->bio ?? '') !== '')
        <section class="author-box" aria-labelledby="author-{{ $loop->index }}">
            <h2 id="author-{{ $loop->index }}">About {{ $author->name }}</h2>
            <p>{{ $author->bio }}</p>
            @if ($author->url !== '')
                <p class="meta"><a href="{{ $author->url }}">More from {{ $author->name }}</a></p>
            @endif
        </section>
    @endif
@endforeach
