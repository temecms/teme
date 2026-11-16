{{-- The holding page: core's own, drawn before any theme on the one card, so it reads nothing but its two values. --}}
<x-teme::standalone :holding="true" :title="__(':address is being set up', ['address' => $address])">
    <p class="hold-addr"><span class="sa-sr-only">{{ __('Address: ') }}</span>{{ $address }}</p>
    <h1>{{ __('This site is being set up and will open soon.') }}</h1>
    <hr>
    <div class="sa-notice">
        <p>{{ __('Is this your site?') }}</p>
        <p>{{ __('Finish setting it up on the setup page. You’ll need the setup code from the end of the deploy log.') }}</p>
        <a class="sa-button" href="{{ $setupUrl }}">{{ __('Set up the site') }}</a>
    </div>
    <p data-foot>{{ __('Visitors see this page until the first administrator account exists. Search engines are asked to leave it out.') }}</p>
</x-teme::standalone>
