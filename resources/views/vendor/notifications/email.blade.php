<x-mail::message>
{{-- Greeting: a plain paragraph, never a heading --}}
@if (! empty($greeting))
<x-mail::words>{{ $greeting }}</x-mail::words>

@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
<x-mail::words>{{ $salutation }}</x-mail::words>
@else
<x-mail::words>{{ $siteLine ?? '' }}</x-mail::words>
@endif

{{-- Subcopy: why this came, then Laravel's line about the button --}}
@if (! empty($footer) || isset($actionText))
<x-slot:subcopy>
@foreach ($footer ?? [] as $line)
<x-mail::words>{{ $line }}</x-mail::words>

@endforeach
@isset($actionText)
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
@endisset
</x-slot:subcopy>
@endif
</x-mail::message>
