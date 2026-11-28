{{-- Core's fields that every comment form carries: the hidden path, parent and token fields, the honeypot and the thread script. A theme's override of x-teme::comment-form includes it inside its form, as core's form does. --}}
<input type="hidden" name="path" value="{{ $form->path }}">
<input type="hidden" name="parent" value="{{ $form->parentId ?? '' }}">
<input type="hidden" name="token" value="">
<input type="hidden" name="_token" value="">
{{-- The honeypot: no person sees or hears it; a bot that fills it is answered as held, and nothing is kept. --}}
<div class="teme-hp" aria-hidden="true"><label for="c-subject">{{ __('Leave this empty') }}</label><input id="c-subject" name="subject" tabindex="-1" autocomplete="off"></div>
@if ($field !== null)
{{-- The comment-check plugin's own field: a fixed value, the browser's time (its script fills it when the form is first touched, so a cached page never carries a stale time), or a trap no person sees. --}}
@if ($field['make'] === 'trap')
<div class="teme-hp" aria-hidden="true"><label for="c-{{ $field['name'] }}">{{ __('Leave this empty') }}</label><input id="c-{{ $field['name'] }}" name="{{ $field['name'] }}" tabindex="-1" autocomplete="off"></div>
@elseif ($field['make'] === 'time')
<input type="hidden" name="{{ $field['name'] }}" value="" data-teme-time>
@else
<input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
@endif
@endif
<x-teme::thread-script />
