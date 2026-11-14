<li data-teme-comment="{{ $comment->id }}">
    @if ($comment->removed)
        <p>This comment was removed.</p>
    @elseif ($comment->kind !== 'comment')
        <p>Linked from {{ $comment->name }}</p>
    @else
        <p><strong>{{ $comment->name }}</strong></p>
        <div>{!! $comment->bodyHtml !!}</div>
        @if ($comment->replyUrl !== null)
            <p><a href="{{ $comment->replyUrl }}" data-teme-reply="{{ $comment->id }}">Reply</a></p>
        @endif
    @endif
    @if ($comment->replies !== [])
        <ol data-teme-replies>
            @foreach ($comment->replies as $reply)
                @include('theme::partials.comment', ['comment' => $reply])
            @endforeach
        </ol>
    @endif
</li>
