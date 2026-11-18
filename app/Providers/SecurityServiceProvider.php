<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Security\Listeners\RecordSecurityEvents;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Users\Roles;
use App\Domain\Users\Security\BreachedPasswordVerifier;
use App\Domain\Users\Security\PasswordHasher;
use App\Http\Admin\Auth\Listeners\ClearSignedInMarker;
use App\Http\Admin\Auth\Listeners\ClearThrottleAfterReset;
use App\Http\Admin\Auth\Listeners\RecordFailedSignIn;
use App\Http\Admin\Auth\Listeners\RecordSignIn;
use App\Http\Admin\Auth\Listeners\SetSignedInMarker;
use App\Http\Admin\Auth\NeutralPasswordResetLinkResponse;
use App\Http\Admin\Auth\ThrottleSignIn;
use App\Http\Admin\Auth\UpgradeHashAfterSignIn;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Sign-in and admin security, wired in one place. docs/architecture/security.md.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerHasher();
        $this->registerBreachedPasswordVerifier();
        // Scoped, so an operator's elevation a queue job binds for its own
        // work ends with that job: the worker forgets it before the next
        // (issue 53).
        $this->app->scoped(Elevation::class, DeniedElevation::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        $this->registerSettings();

        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            ThrottleSignIn::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
            UpgradeHashAfterSignIn::class,
        ]));

        Event::subscribe(RecordSecurityEvents::class);
        Event::listen(Failed::class, RecordFailedSignIn::class);
        Event::listen(Login::class, RecordSignIn::class);
        Event::listen(PasswordReset::class, ClearThrottleAfterReset::class);

        // The marker the page cache steps aside for (I-262).
        Event::listen(Login::class, SetSignedInMarker::class);
        Event::listen(Logout::class, ClearSignedInMarker::class);

        // Study G, S3: length resists guessing; composition rules do not.
        // Applied when a password is set, never at sign-in.
        Password::defaults(function (): Password {
            $settings = $this->app->make(SettingsReader::class);
            $rule = Password::min($settings->int('security', 'password_min_length'))->max(256);

            return $settings->bool('security', 'password_check_breached') ? $rule->uncompromised() : $rule;
        });
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(new SettingsGroup(
            key: 'security',
            label: 'Security',
            description: 'Password rules, sign-in limits and session lengths. Changing any of these asks you to confirm your password.',
            section: 'security',
            ability: 'settings.security.manage',
            settings: [
                Setting::number('password_min_length', 'Shortest password', 'The fewest characters a new password may have. Applies when a password is set, never at sign-in.', '0.1.0')
                    ->default(15)->min(8)->max(64)->unit('characters')->required(),
                Setting::boolean('password_check_breached', 'Check new passwords against known leaks', 'Checks new passwords against Have I Been Pwned without sending the password. Turn off if your server can’t reach the internet.', '0.1.0')
                    ->default(true),
                Setting::number('login_attempts_per_account_ip', 'For one account, from one IP address', 'Failed sign-ins allowed for one account from one IP address in 15 minutes.', '0.1.0')
                    ->default(5)->min(1)->max(100)->unit('in 15 minutes'),
                Setting::number('login_attempts_per_ip', 'From one IP address, across all accounts', 'Failed sign-ins allowed from one IP address, across all accounts, in an hour.', '0.1.0')
                    ->default(50)->min(1)->max(10000)->unit('an hour'),
                Setting::number('login_untrusted_attempts_per_account', 'For one account, from browsers that never signed in to it', 'Browsers that have signed in before are not held back by this limit.', '0.1.0')
                    ->default(10)->min(1)->max(1000)->unit('an hour'),
                Setting::number('session_idle_minutes', 'End a session after', 'Minutes without activity before a session ends.', '0.1.0')
                    ->default(120)->min(5)->max(1440)->unit('minutes without activity'),
                Setting::number('session_absolute_hours', 'End every session after', 'Hours after signing in when a session ends, however active it is.', '0.1.0')
                    ->default(24)->min(1)->max(24)->unit('hours'),
                Setting::number('session_remember_days', 'Keep a browser signed in for', 'Days a browser stays signed in when “Remember me” is ticked. 0 removes the option.', '0.1.0')
                    ->default(14)->min(0)->max(30)->unit('days with “Remember me”'),
                Setting::number('elevation_minutes', 'A password confirmation lasts', 'Minutes a password confirmation lasts before a sensitive change asks for it again.', '0.1.0')
                    ->default(15)->min(1)->max(60)->unit('minutes'),
                Setting::multiselect('two_factor_required_roles', 'Roles that must use two-factor authentication', 'Members of these roles must set up two-factor authentication before they can use the admin. None are required on a new site.', '0.1.0', Roles::LABELS),
            ],
            since: '0.1.0',
            sensitive: true,
        ));
    }

    /**
     * `extend`, not `singleton`: Laravel's ValidationServiceProvider is
     * deferred and binds its own verifier when first resolved, after ours,
     * which would silently replace a plain binding. An extender applies
     * whichever binding wins.
     */
    private function registerBreachedPasswordVerifier(): void
    {
        $this->app->extend(UncompromisedVerifier::class, fn (): BreachedPasswordVerifier => new BreachedPasswordVerifier);
    }

    /**
     * Through the container, so the driver exists however early something
     * first hashes a value.
     */
    private function registerHasher(): void
    {
        $this->app->extend('hash', function (HashManager $manager): HashManager {
            $manager->extend('teme', function (Application $app): PasswordHasher {
                /** @var array<string, mixed> $options */
                $options = $app['config']->get('hashing.argon', []);

                return new PasswordHasher($options);
            });

            return $manager;
        });
    }
}
