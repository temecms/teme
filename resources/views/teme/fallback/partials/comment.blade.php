{{-- One place in core's own thread, and its replies: a comment, a tombstone or a ping. Reads only $comment, a CommentView. --}}
<li id="comment-{{ $comment->id }}">
    @if ($comment->removed)
        <p>{{ __('This comment was removed.') }}</p>
    @else
        <p><strong>{{ $comment->name }}</strong> <time datetime="{{ $comment->iso }}">{{ $comment->dateText }}</time></p>
        {!! $comment->bodyHtml !!}
    @endif
    @if ($comment->replies !== [])
        <ol>
            @foreach ($comment->replies as $reply)
                @include('teme::fallback.partials.comment', ['comment' => $reply])
            @endforeach
        </ol>
    @endif
</li>
