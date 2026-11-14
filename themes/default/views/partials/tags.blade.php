{{-- All of an article's tags, given $tags; nothing without any. --}}
@if ($tags !== [])
<ul class="tags" aria-label="Tags">
    @foreach ($tags as $tag)
        <li><a href="{{ $tag->url }}" rel="tag">{{ $tag->name }}</a></li>
    @endforeach
</ul>
@endif
