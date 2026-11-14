{{-- An item's shown fields, label and value, as the core hands them over: nothing when there are none. --}}
@if ($fields !== [])
    <dl class="field-list">
        @foreach ($fields as $field)
            <div>
                <dt>{{ $field->label }}</dt>
                <dd>
                    @if ($field->image !== null)
                        <x-teme::image :id="$field->image->id" context="content" :alt="$field->image->alt" :decorative="$field->image->decorative" />
                    @elseif ($field->url !== null)
                        <a href="{{ $field->url }}">{{ $field->text }}</a>
                    @elseif ($field->type === 'textarea')
                        {!! implode('<br>', array_map('e', preg_split('/\R/', $field->text))) !!}
                    @else
                        {{ $field->text }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
