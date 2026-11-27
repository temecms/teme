{{-- Core's fields that every comment form carries: the hidden path, parent and token fields, the honeypot and the thread script. A theme's override of x-teme::comment-form includes it inside its form, as core's form does. --}}
<input type="hidden" name="path" value="{{ $form->path }}">
<input type="hidden" name="parent" value="{{ $form->parentId ?? '' }}">
<input type="hidden" name="token" value="">
<input type="hidden" name="_token" value="">
{{-- The honeypot: no person sees or hears it; a bot that fills it is answered as held, and nothing is kept. --}}
<div class="teme-hp" aria-hidden="true"><label for="c-subject">{{ __('Leave this empty') }}</label><input id="c-subject" name="subject" tabindex="-1" autocomplete="off"></div>
<x-teme::thread-script />
