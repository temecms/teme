{{-- Core's form for an item protected by a password: no script, posted to core's own address, never stored. --}}
<form class="teme-password-form" method="post" action="/_teme/unlock" novalidate aria-labelledby="page-h">
    <input type="hidden" name="path" value="{{ $page->path }}">
    <div class="teme-field">
        <label for="teme-password">{{ __('Password') }}</label>
        <input id="teme-password" name="password" type="password" autocomplete="current-password" required maxlength="255"
            @if ($page->error !== null) aria-invalid="true" aria-describedby="teme-password-error" autofocus @endif>
        @if ($page->error !== null)
            <p class="teme-errors" id="teme-password-error">{{ $page->error }}</p>
        @endif
    </div>
    <div class="teme-submit">
        <button class="button" type="submit">{{ __('Unlock') }}</button>
    </div>
</form>
