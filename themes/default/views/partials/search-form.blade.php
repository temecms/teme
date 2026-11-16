{{-- A plain GET search, given its field's own id and value: a page may draw several. --}}
<form class="search" role="search" action="{{ $site->url }}" method="get">
    <label class="sr-only" for="{{ $id }}">{{ __('Search') }}</label>
    <input id="{{ $id }}" name="s" type="search" value="{{ $value }}">
    <button class="button quiet" type="submit">{{ __('Search') }}</button>
</form>
