{{-- One field of core's comment form, with its hint and, when the form comes back refused, its error (D14). Included, not a component: it reads $id, $name, $label and $hint, and $type, $autocomplete, $width, $optional and $textarea when given, with the form's $form. --}}
@php
    $fieldError = collect($form->errors)->first(fn (array $candidate): bool => $candidate['field'] === $name);
    $optional = $optional ?? false;
    $describedBy = $id.'-hint'.($fieldError !== null ? ' '.$id.'-error' : '');
@endphp
<div class="teme-field">
    <label for="{{ $id }}">{{ $label }}@if ($optional) <span class="teme-optional">{{ __('(optional)') }}</span>@endif</label>
    <p class="teme-hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @if ($fieldError !== null)
        <p class="teme-error" id="{{ $id }}-error"><span class="teme-sr-only">{{ __('Error:') }} </span>{{ $fieldError['message'] }}</p>
    @endif
    @if ($textarea ?? false)
        <textarea id="{{ $id }}" name="{{ $name }}" rows="6" aria-describedby="{{ $describedBy }}"@if ($fieldError !== null) aria-invalid="true"@endif @unless ($optional) required @endunless>{{ $form->values[$name] ?? '' }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type ?? 'text' }}"@isset ($autocomplete) autocomplete="{{ $autocomplete }}"@endisset @isset ($width) data-width="{{ $width }}"@endisset @if (($type ?? 'text') !== 'text') spellcheck="false" autocapitalize="off"@endif aria-describedby="{{ $describedBy }}"@if ($fieldError !== null) aria-invalid="true"@endif @unless ($optional) required @endunless value="{{ $form->values[$name] ?? '' }}">
    @endif
</div>
