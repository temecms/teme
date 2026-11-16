{{-- Who wrote it and when, given $authors, $published and $publishedText; "Updated" and the reading time only when given. --}}
@if ($authors !== [])
    {{ __('By') }} @foreach ($authors as $author){{ $loop->first ? '' : ($loop->last ? ' '.__('and').' ' : ', ') }}@if ($author->url !== '')<a href="{{ $author->url }}" rel="author">{{ $author->name }}</a>@else{{ $author->name }}@endif @endforeach ·
@endif
<time datetime="{{ $published->format(DATE_ATOM) }}">{{ $publishedText }}</time>
@if (($updated ?? null) !== null && ($updatedText ?? null) !== null)
    · <span>{{ __('Updated') }} <time datetime="{{ $updated->format(DATE_ATOM) }}">{{ $updatedText }}</time></span>
@endif
@if (($readingMinutes ?? null) !== null)
    · <span>{{ trans_choice(':count min read', $readingMinutes, ['count' => $readingMinutes]) }}</span>
@endif
