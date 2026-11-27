@if ($page->headings !== [])
    <nav aria-label="{{ __('In this story') }}">
        <ol>@foreach ($page->headings as $heading)
            <li><a href="#{{ $heading['id'] }}">{{ $heading['text'] }}</a></li>
        @endforeach</ol>
    </nav>
@endif
