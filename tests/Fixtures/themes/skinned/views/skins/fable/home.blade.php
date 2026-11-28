<x-teme::document>
<main id="main">
    <h1>{{ $site->name }}</h1>
    @foreach ($page->feed->items as $item)
        <article><a href="{{ $item->url }}">{{ $item->title }}</a></article>
        <x-teme::between-items :after="$loop->iteration" :last="$loop->last" />
    @endforeach
</main>
</x-teme::document>
