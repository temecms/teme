{{--
    The thread, given $comments, under a story (study Q §4: after the after-content region, before related posts) or on
    a later page of comments. Nothing when there is none: comments switched off, the item Hidden, or its type taking none
    (I-484, I-274); nothing, too, when comments are closed and there are none, as WordPress's default themes do. The form
    in it is core's. Core's script finds the data-teme-* hooks, never these classes (1.8b′), and the thread script it places where there is a thread and no form is core's (1.8c″).
--}}
@if ($comments !== null && ($comments->open || $comments->count > 0))
<section class="comments" id="comments" aria-labelledby="comments-h">
    <h2 class="comments-title" id="comments-h" data-teme-count="{{ $comments->count }}">{{ $comments->count === 0 ? __('Comments') : ($comments->count === 1 ? __('1 comment') : __(':count comments', ['count' => \Illuminate\Support\Number::format($comments->count, locale: app()->getLocale())])) }}</h2>
    @if ($comments->count === 0)
        <p class="c-empty" data-teme-empty>{{ __('No comments yet. Start the conversation.') }}</p>
    @endif
    <ol class="c-thread" data-teme-thread data-teme-depth="{{ $comments->depth }}"@if ($comments->items === []) hidden @endif>
        @foreach ($comments->items as $comment)
            @include('theme::partials.comment', ['comment' => $comment])
        @endforeach
    </ol>
    @if ($comments->total > 1)
        {{-- Oldest first, so back is "Older" and on is "Newer", as the listings' pair and WordPress's. --}}
        <nav class="pagination" aria-label="{{ __('Pages of comments') }}">
            <ol>
                @if ($comments->pagination->previous !== null)
                    <li><a href="{{ $comments->pagination->previous }}" rel="prev">{{ __('Older') }}<span class="sr-only"> {{ __('comments') }}</span></a></li>
                @endif
                @foreach ($comments->pagination->pages() as $link)
                    <li>@if ($link['number'] === null)<span class="gap" aria-hidden="true">…</span>@elseif ($link['current'])<span aria-current="page"><span class="sr-only">{{ __('Page') }} </span>{{ $link['number'] }}</span>@else<a href="{{ $link['url'] }}"><span class="sr-only">{{ __('Page') }} </span>{{ $link['number'] }}</a>@endif</li>
                @endforeach
                @if ($comments->pagination->next !== null)
                    <li><a href="{{ $comments->pagination->next }}" rel="next">{{ __('Newer') }}<span class="sr-only"> {{ __('comments') }}</span></a></li>
                @endif
            </ol>
        </nav>
    @endif
    @if ($comments->form !== null)
        <x-teme::comment-form :form="$comments->form" />
    @else
        <p class="c-closed">{{ __('Comments are closed.') }}</p>
        <x-teme::thread-script />
    @endif
</section>
@endif
