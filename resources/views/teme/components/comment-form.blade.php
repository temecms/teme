{{-- Core's comment form (docs/studies/comments.md §3.4): the theme places and styles it; the fields, their names, the honeypot, the privacy line and the data-teme-* hooks are core's. It posts the item's address, never its ID (1.7c's D11). The parts core's script shows are hidden until it does, and the form uses core's thread script, which shows them (1.8b′, 1.8c″). --}}
@php($reply = $form->parentId !== null)
<div class="teme-respond" id="respond" data-teme-respond>
    @unless ($form->standalone)
        <h{{ $level }} class="teme-respond-title" id="respond-h" tabindex="-1">{{ __('Leave a comment') }}</h{{ $level }}>
    @endunless
    @if ($closedToGuests)
        <p class="teme-signed-in-only">{{ __('Only people signed in to this site can comment here.') }}</p>
    @else
        <form class="teme-comment-form" method="post" action="{{ $form->action }}" novalidate aria-labelledby="{{ $form->standalone ? 'page-h' : 'respond-h' }}" data-teme-form>
            @if ($form->errors !== [])
                <div class="teme-errors" id="respond-errors" tabindex="-1" @if ($form->standalone) autofocus @endif>
                    <div role="alert">
                        <h{{ $level + 1 }}>{{ __('There’s a problem') }}</h{{ $level + 1 }}>
                        <ul>
                            @foreach ($form->errors as $error)
                                <li><a href="#{{ $error['target'] }}">{{ $error['message'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
            @unless ($form->standalone)
                <p class="teme-replying" data-teme-replying hidden>{{ __('Replying to') }} <a href="#respond"></a></p>
            @endunless
            <input type="hidden" name="path" value="{{ $form->path }}">
            <input type="hidden" name="parent" value="{{ $form->parentId ?? '' }}">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="_token" value="">
            @include('teme::components.comment-field', ['id' => 'c-text', 'name' => 'text', 'label' => $reply ? __('Your reply') : __('Comment'), 'hint' => __('Plain text. A blank line starts a new paragraph, and web addresses become links.'), 'textarea' => true])
            <div class="teme-guest" data-teme-guest>
                @include('teme::components.comment-field', ['id' => 'c-name', 'name' => 'name', 'label' => __('Name'), 'hint' => __('Shown with your comment.'), 'type' => 'text', 'autocomplete' => 'name', 'width' => 'name'])
                @include('teme::components.comment-field', ['id' => 'c-email', 'name' => 'email', 'label' => __('Email address'), 'hint' => __('Never shown.'), 'type' => 'email', 'autocomplete' => 'email', 'width' => 'address'])
                @if ($form->askWebsite)
                    @include('teme::components.comment-field', ['id' => 'c-url', 'name' => 'url', 'label' => __('Website'), 'hint' => __('Your name will link to it.'), 'type' => 'url', 'autocomplete' => 'url', 'width' => 'address', 'optional' => true])
                @endif
                <div class="teme-remember" data-teme-remember hidden>
                    <label><input type="checkbox" name="remember" value="1"> {{ $form->askWebsite ? __('Save my name, email address and website in this browser for next time') : __('Save my name and email address in this browser for next time') }}</label>
                    <p class="teme-hint">{{ __('Either way, this browser keeps a random key, so it can show you your own comment while it waits.') }}</p>
                </div>
            </div>
            <p class="teme-as" data-teme-as hidden>{{ __('You’re signed in, so you’ll comment as') }} <strong></strong>.</p>
            {{-- The honeypot (I-578, D8): no person sees or hears it; a bot that fills it is answered as held, and nothing is kept. --}}
            <div class="teme-hp" aria-hidden="true"><label for="c-subject">{{ __('Leave this empty') }}</label><input id="c-subject" name="subject" tabindex="-1" autocomplete="off"></div>
            <p class="teme-privacy">{{ $form->askWebsite ? __('Your name and website are shown with your comment; your email address never is.') : __('Your name is shown with your comment; your email address never is.') }} {{ __('To fight spam, we keep your IP address and browser details for :days days, then delete them.', ['days' => $form->keepIpDays]) }}</p>
            <div class="teme-submit">
                <button class="button" type="submit">{{ $reply ? __('Post reply') : __('Post comment') }}</button>
                @unless ($form->standalone)
                    <button class="button quiet" type="button" data-teme-cancel hidden>{{ __('Cancel reply') }}</button>
                @endunless
                <p class="teme-status" role="status" data-teme-status></p>
            </div>
        </form>
        {{-- Never with the line for signed-in people only: there is no form to help. --}}
        <x-teme::thread-script />
    @endif
</div>
