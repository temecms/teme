{{-- Core's error page: drawn when the theme has none, or its own fails. Reads only $page. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->code }}</title>
</head>
<body>
<main data-fallback="error">
    <h1>{{ $page->code }}</h1>
    <p>{{ __('Something went wrong with this page.') }}</p>
</main>
</body>
</html>
