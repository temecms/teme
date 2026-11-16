{{-- One place in the thread, given $comment: a comment, a tombstone (I-278) or a ping (I-277), with its replies nested under it. A tombstone or ping imported from WordPress keeps its old #comment-N anchor. --}}
<li class="c-item{{ $comment->pending ? ' is-pending' : '' }}" id="comment-{{ $comment->id }}" data-teme-comment="{{ $comment->id }}">
    @if ($comment->removed)
        <div class="c-comment c-removed"@if ($comment->legacyId !== null) id="comment-{{ $comment->legacyId }}"@endif><span class="c-avatar is-empty" aria-hidden="true"></span><p>{{ __('This comment was removed.') }}</p></div>
    @elseif ($comment->kind !== 'comment')
        <p class="c-comment c-ping"@if ($comment->legacyId !== null) id="comment-{{ $comment->legacyId }}"@endif><span>{{ __('Linked from') }} <a href="{{ $comment->url }}" rel="ugc nofollow">{{ $comment->name }}</a></span><time datetime="{{ $comment->iso }}">{{ $comment->dateText }}</time></p>
    @else
        @include('theme::partials.comment-card', ['comment' => $comment, 'withReply' => true])
    @endif
    @if ($comment->replies !== [])
        <ol class="c-replies" data-teme-replies>
            @foreach ($comment->replies as $reply)
                @include('theme::partials.comment', ['comment' => $reply])
            @endforeach
        </ol>
    @endif
</li>
