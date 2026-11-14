{{-- A listing's pages, given $pagination: Previous, the page links core works out, and Next. --}}
@if ($pagination->previous !== null || $pagination->next !== null)
<nav class="pagination" aria-label="Pages">
    <ol>
        @if ($pagination->previous !== null)
            <li><a href="{{ $pagination->previous }}" rel="prev">Previous</a></li>
        @endif
        @foreach ($pagination->pages() as $link)
            <li>@if ($link['number'] === null)<span class="gap" aria-hidden="true">…</span>@elseif ($link['current'])<span aria-current="page"><span class="sr-only">Page </span>{{ $link['number'] }}</span>@else<a href="{{ $link['url'] }}"><span class="sr-only">Page </span>{{ $link['number'] }}</a>@endif</li>
        @endforeach
        @if ($pagination->next !== null)
            <li><a href="{{ $pagination->next }}" rel="next">Next</a></li>
        @endif
    </ol>
</nav>
@endif
