{{-- Core's comments script, once a page, after the session script it reads, wherever a thread is drawn, with a form or without. --}}
@pushOnce('teme-core-scripts', 'teme-comments-script')
    @include('teme::components.comments-script')
@endPushOnce
