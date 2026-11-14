{{-- One listed item, given $item, the image $context (card, thumb or lead), $showImage and $showMeta, and optionally $priority, $level, $class and a search result's $snippetHtml, shown in place of the standfirst. The whole card is clickable through its title link, stretched with CSS. --}}
<article class="card{{ $showImage && $item->image !== null ? ' has-media' : '' }}{{ ($class ?? '') !== '' ? ' '.$class : '' }}">
    @if ($showImage && $item->image !== null)
        <div class="card-media"><x-teme::image :id="$item->image->id" :context="$context" :priority="$priority ?? false" :decorative="true" /></div>
    @endif
    <div class="card-text">
        @include('theme::partials.kicker', ['kicker' => $item->kicker])
        <h{{ $level ?? 2 }} class="card-title">@if ($item->url !== '')<a href="{{ $item->url }}">{{ $item->title }}</a>@else{{ $item->title }}@endif</h{{ $level ?? 2 }}>
        @if (($snippetHtml ?? null) !== null)
            {{-- The one raw output of search results: the snippet was escaped before its matches were marked (App\Domain\Search\Snippet). --}}
            <p class="standfirst snippet">{!! $snippetHtml !!}</p>
        @elseif ($item->standfirst !== null && $item->standfirst !== '')
            <p class="standfirst">{{ $item->standfirst }}</p>
        @endif
        @if ($showMeta)
            <p class="meta">@include('theme::partials.byline', ['authors' => $item->authors, 'published' => $item->published, 'publishedText' => $item->publishedText, 'updated' => null, 'updatedText' => null, 'readingMinutes' => null])</p>
        @endif
    </div>
</article>
