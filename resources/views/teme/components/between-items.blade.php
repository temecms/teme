{{-- What plugins place between a list's items (docs/plans/plugin-sockets-2.md, B2), each cleaned, spanning a grid's whole row. --}}
@foreach ($html as $markup)
<div class="teme-between-items" style="grid-column:1/-1">{!! $markup !!}</div>
@endforeach
