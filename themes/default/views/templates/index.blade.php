{{-- Every theme's floor (1.6b): any kind of page may land here, with any view model, so it reads nothing from $page. With this theme's templates, none does. --}}
@extends('theme::layouts.base')

@section('main')
    <div class="wrap">
        <p>This page has no template of its own.</p>
    </div>
@endsection
