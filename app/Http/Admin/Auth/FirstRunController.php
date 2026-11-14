<?php

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use App\Domain\Users\Operations\Input\SetUpSiteInput;
use App\Domain\Users\Operations\SetUpSite;
use App\Domain\Users\Queries\FirstRun;
use App\Domain\Users\SetupCode;
use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run page (issue 246): shows the form while no account exists, and
 * hands it to the `SetUpSite` Operation.
 */
final class FirstRunController extends Controller
{
    private const int TRIES_PER_MINUTE = 5;

    public function show(FirstRun $firstRun): Response|RedirectResponse
    {
        if (! $firstRun->needed()) {
            return $this->alreadySetUp();
        }

        return Inertia::render('auth/set-up', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, SetUpSite $setUpSite, SetupCode $setupCode, FirstRun $firstRun, StatefulGuard $guard): RedirectResponse
    {
        if (! $firstRun->needed()) {
            return $this->alreadySetUp();
        }

        $tries = 'first-run:'.$request->ip();

        // Counted before the code is checked, in one increment, so a burst of posts can't all pass a check made first.
        if (RateLimiter::hit($tries, 60) > self::TRIES_PER_MINUTE) {
            throw ValidationException::withMessages([
                'code' => __('Too many tries. Wait :seconds seconds, then try again.', ['seconds' => (string) RateLimiter::availableIn($tries)]),
            ]);
        }

        $form = $this->form($request);

        try {
            $result = $setUpSite->execute(new SetUpSiteInput(
                code: $form['code'],
                siteTitle: $form['site_title'],
                timezone: $form['timezone'],
                name: $form['name'],
                email: $form['email'],
                password: $form['password'],
                passwordConfirmation: $form['password_confirmation'],
            ));
        } catch (AuthorizationException) {
            // Someone else finished first, or the code is wrong.
            if (! $firstRun->needed()) {
                return $this->alreadySetUp();
            }

            throw ValidationException::withMessages([
                'code' => __('That setup code isn’t right. Copy it from the deploy log, or run php artisan teme:setup-code on the server.'),
            ]);
        } catch (ValidationException $refusal) {
            // Cleared only for the right code in a form a browser sends, so the owner fixing a field keeps every try and a guesser keeps none.
            if (! $form['malformed'] && $setupCode->matches($form['code'])) {
                RateLimiter::clear($tries);
            }

            throw $refusal;
        }

        RateLimiter::clear($tries);
        $guard->loginUsingId($result['userId']);
        $request->session()->regenerate();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your site is set up. Next, turn on two-factor sign-in in Account › Security.')]);

        return to_route('dashboard');
    }

    /**
     * The form's fields as text: a field sent as anything else, such as a
     * list, reads as empty, so validation refuses it instead of the request
     * failing, and `malformed` says one was.
     *
     * @return array{code: string, site_title: string, timezone: string, name: string, email: string, password: string, password_confirmation: string, malformed: bool}
     */
    private function form(Request $request): array
    {
        $form = ['malformed' => false];

        foreach (['code', 'site_title', 'timezone', 'name', 'email', 'password', 'password_confirmation'] as $field) {
            $value = $request->input($field);
            $form[$field] = is_string($value) ? $value : '';
            $form['malformed'] = $form['malformed'] || ($value !== null && ! is_string($value));
        }

        return $form;
    }

    private function alreadySetUp(): RedirectResponse
    {
        return to_route('login')->with('status', __('This site is already set up. Sign in to continue.'));
    }
}
