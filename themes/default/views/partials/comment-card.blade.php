{{-- A comment, given $comment and $withReply: who and when first, then the words, then its actions line, core's place for Edit (1.8c″), with Reply in it while offered. Named for assistive technology by its writer and time. Its words are core's, escaped (I-267); the story's author is marked from the account (I-597). --}}
<article class="c-comment"@if ($comment->legacyId !== null) id="comment-{{ $comment->legacyId }}"@endif aria-labelledby="c-{{ $comment->id }}-by c-{{ $comment->id }}-at{{ $comment->pending ? ' c-'.$comment->id.'-state' : '' }}">
    @if ($comment->pending)
        <p class="c-state" id="c-{{ $comment->id }}-state"><strong>{{ __('Awaiting moderation.') }}</strong> {{ __('Only you can see it until a moderator approves it.') }}</p>
    @endif
    <header class="c-head">
        <span class="c-avatar" data-hue="{{ $comment->hue }}" aria-hidden="true">{{ $comment->initials }}</span>
        <p class="c-who">
            <span class="c-name" id="c-{{ $comment->id }}-by">@if ($comment->url !== null)<a href="{{ $comment->url }}" rel="ugc nofollow">{{ $comment->name }}</a>@else{{ $comment->name }}@endif @if ($comment->byAuthor)<span class="c-badge">{{ __('Author') }}</span>@endif</span>
            @if ($comment->pending)
                <span class="c-time"><time id="c-{{ $comment->id }}-at" datetime="{{ $comment->iso }}">{{ $comment->dateText }}</time></span>
            @else
                <a class="c-time" href="{{ $comment->permalink }}"><time id="c-{{ $comment->id }}-at" datetime="{{ $comment->iso }}">{{ $comment->dateText }}</time><span class="sr-only">, {{ __(':name’s comment', ['name' => $comment->name]) }}</span></a>
            @endif
        </p>
    </header>
    @if ($comment->isReply)
        <p class="c-inreply">{{ $comment->parentName === null ? __('In reply to a removed comment') : __('In reply to :name', ['name' => $comment->parentName]) }}</p>
    @endif
    <div class="teme-comment-body">{!! $comment->bodyHtml !!}</div>
    @if ($withReply && ! $comment->pending)
        @php($canReply = $comment->replyUrl !== null)
        <p class="c-actions" data-teme-actions="{{ $comment->name }}"@unless ($canReply) hidden @endunless>@if ($canReply)<a class="c-reply" href="{{ $comment->replyUrl }}" rel="nofollow" data-teme-reply="{{ $comment->id }}">{{ __('Reply') }}<span class="sr-only"> {{ __('to :name', ['name' => $comment->name]) }}</span></a>@endif</p>
    @endif
</article>
