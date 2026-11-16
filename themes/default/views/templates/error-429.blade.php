{{-- Too many searches from one reader (1.6h's rate limit): the words are the template's, as ErrorView carries only the code (B-280). --}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap notfound">
        <h1>{{ __('Too many searches') }}</h1>
        <p>{{ __('Please wait a minute and try again.') }}</p>
        <p><a href="{{ $site->url }}">{{ __('Go to the home page') }}</a></p>
    </div>
@endsection
