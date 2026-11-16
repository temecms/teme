{{-- Any other error: minimal, reading nothing from $page, so it has nothing to fail on. --}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap notfound">
        <h1>{{ __('Something went wrong') }}</h1>
        <p><a href="{{ $site->url }}">{{ __('Go to the home page') }}</a></p>
    </div>
@endsection
