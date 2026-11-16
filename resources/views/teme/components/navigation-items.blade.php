{{-- One level of a menu, and the levels under it. A heading is a button that opens the list after it, which it names by id; the list is drawn open, so its items are there with no script, and core's menu script folds it. A link and its sub-items are drawn as before. --}}
@if (($listId ?? null) !== null)
<ul id="{{ $listId }}" data-teme-submenu>
@else
<ul>
@endif
@foreach ($items as $item)
@php($place = ($idBase ?? 'teme-menu').'-'.$loop->iteration)
@if ($item['heading'] ?? false)
<li><button type="button" data-teme-heading aria-expanded="true" aria-controls="{{ $place }}">{{ $item['label'] }}</button>
@include('teme::components.navigation-items', ['items' => $item['children'], 'idBase' => $place, 'listId' => $place])
@include('teme::components.menu-script')
</li>
@else
<li><a href="{{ $item['url'] }}"{!! $item['current'] ? ' aria-current="page"' : '' !!}{!! $item['opensNewTab'] ? ' target="_blank" rel="noopener"' : '' !!}>{{ $item['label'] }}</a>
@if ($item['children'] !== [])
@include('teme::components.navigation-items', ['items' => $item['children'], 'idBase' => $place, 'listId' => null])
@endif
</li>
@endif
@endforeach
</ul>
