{{-- Nothing at this address: said plainly, with a search and a way home. The status stays 404 (1.6b). --}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap notfound">
        <h1>We couldn’t find that page</h1>
        <p>It may have moved, or the address may have a typo. Try a search, or start from the home page.</p>
        @include('theme::partials.search-form', ['id' => 'search-notfound', 'value' => ''])
        <p><a href="{{ $site->url }}">Go to the home page</a></p>
    </div>
@endsection
