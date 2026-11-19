<?php

/**
 * Makes a new site's first administrator and names the site, once, while no
 * account exists (issue 246). Authorised by the setup code; under a lock, so
 * two people submitting at once can't both win.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Input\SetUpSiteInput;
use App\Domain\Users\Queries\FirstRun;
use App\Domain\Users\Roles;
use App\Domain\Users\SetupCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class SetUpSite extends BaseOperation
{
    /** Held for the transaction; the second of two requests waits here, then finds the first's account. */
    private const string LOCK = 'teme.first-run';

    public function __construct(
        private readonly SetupCode $code,
        private readonly FirstRun $firstRun,
        private readonly SettingsRegistry $registry,
        private readonly SettingsWriter $writer,
        private readonly SecurityLog $log,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SetUpSiteInput $input */
        if (! $this->firstRun->needed() || ! $this->code->matches($input->code)) {
            throw new AuthorizationException('Not permitted to set up this site.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetUpSiteInput $input */
        $general = $this->registry->find('general') ?? throw new LogicException('The general settings group is missing.');
        $title = $general->setting('site_title') ?? throw new LogicException('The site title setting is missing.');
        $zone = $general->setting('timezone') ?? throw new LogicException('The time zone setting is missing.');

        Validator::make(
            [
                'site_title' => trim($input->siteTitle),
                'timezone' => $input->timezone,
                'name' => trim($input->name),
                'email' => Str::lower(trim($input->email)),
                'password' => $input->password,
                'password_confirmation' => $input->passwordConfirmation,
            ],
            [
                'site_title' => SettingRules::for($title),
                'timezone' => ['required', ...SettingRules::for($zone)],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ],
        )->validate();
    }

    /**
     * @return array{userId: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SetUpSiteInput $input */
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);

        // Checked again under the lock: another request may have made the first account since authorize().
        if (! $this->firstRun->needed()) {
            throw new AuthorizationException('This site is already set up.');
        }

        $email = Str::lower(trim($input->email));

        $user = new User;
        $user->name = trim($input->name);
        $user->email = $email;
        $user->password = $input->password;
        // Nothing proves the address yet, as with WordPress's and Ghost's first account; a fresh server may have no mail.
        $user->email_verified_at ??= Date::now();
        $user->save();
        $user->assignRole(Roles::ADMINISTRATOR);

        app(PathProjection::class)->syncUser($user);
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, Footprints::person($user)));

        $this->writer->write(new SettingsWrite(
            group: 'general',
            values: ['site_title' => trim($input->siteTitle), 'timezone' => $input->timezone, 'admin_email' => $email],
            actorId: (string) $user->id,
        ));

        $this->log->record(SecurityEventType::SiteSetUp, $user->id);

        return ['userId' => $user->id];
    }
}
