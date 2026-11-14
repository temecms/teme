{{-- A slot's menu (I-253), or, outside production, a loud note that it has none (study B, decision 2). A menu with nothing to show draws no landmark. --}}
@if ($items !== null)
@if ($items !== [])
<nav aria-label="{{ $label }}">
@include('teme::components.navigation-items', ['items' => $items, 'idBase' => $menuId, 'listId' => null])
</nav>
@endif
@elseif ($showUnbound)
<nav data-teme-unbound="{{ $slotKey }}" class="teme-unbound">No menu is shown here: "{{ $slotLabel }}" has no menu. Choose one under Content › Navigation.</nav>
@endif
