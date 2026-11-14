{{-- Core's comments script, once a page, after the session script it reads (1.8b′'s D1, amended by 1.8c″'s D3: where a thread is drawn, form or not). --}}
@pushOnce('teme-core-scripts', 'teme-comments-script')
    @include('teme::components.comments-script')
@endPushOnce
