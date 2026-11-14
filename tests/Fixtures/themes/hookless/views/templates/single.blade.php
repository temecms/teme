<x-teme::document>
<main id="main" data-template="single">
    <h1>{{ $page->title }}</h1>
    @if ($page->comments !== null)
        <section id="comments">
            <h2>Comments</h2>
            <ol data-teme-thread data-teme-depth="{{ $page->comments->depth }}"@if ($page->comments->items === []) hidden @endif>
                @foreach ($page->comments->items as $comment)
                    @include('theme::partials.comment', ['comment' => $comment])
                @endforeach
            </ol>
            @if ($page->comments->form !== null)
                <x-teme::comment-form :form="$page->comments->form" />
            @endif
        </section>
    @endif
</main>
</x-teme::document>
