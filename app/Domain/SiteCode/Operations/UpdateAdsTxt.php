<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations;

use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\SiteCode\Operations\Input\UpdateAdsTxtInput;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodeSettings;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Saves ads.txt on Settings › Site code: the text, or a permanent redirect
 * to an https address on another host. Whoever holds site-code.manage, against
 * the version the form was opened on.
 */
final class UpdateAdsTxt extends BaseOperation
{
    /** An https address with a host, then anything without spaces. */
    private const string HTTPS_ADDRESS = '~^https://[^\s/?#@]+(?:[/?#]\S*)?$~iu';

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var UpdateAdsTxtInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can(SiteCode::ABILITY)) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateAdsTxtInput $input */
        $group = $this->registry->find(SiteCodeSettings::KEY)
            ?? throw new RuntimeException('No settings group ['.SiteCodeSettings::KEY.'] is registered.');

        $adsTxt = $group->setting(SiteCodeSettings::ADS_TXT)
            ?? throw new RuntimeException('No setting ['.SiteCodeSettings::ADS_TXT.'] is registered.');

        Validator::make(
            [SiteCodeSettings::ADS_TXT => $input->adsTxt],
            [SiteCodeSettings::ADS_TXT => SettingRules::for($adsTxt)],
        )->validate();

        $mode = trim($input->mode);
        $redirect = trim($input->redirectUrl);
        $problems = [];

        if (! in_array($mode, [SiteCodeSettings::MODE_TEXT, SiteCodeSettings::MODE_REDIRECT], true)) {
            $problems[SiteCodeSettings::ADS_TXT_MODE] = __('Choose text or a redirect.');
        }

        if ($mode === SiteCodeSettings::MODE_REDIRECT) {
            $problem = self::redirectProblem($redirect);

            if ($problem !== null) {
                $problems[SiteCodeSettings::ADS_TXT_REDIRECT] = $problem;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var UpdateAdsTxtInput $input */
        return $this->writer->write(new SettingsWrite(
            group: SiteCodeSettings::KEY,
            values: [
                SiteCodeSettings::ADS_TXT => $input->adsTxt,
                SiteCodeSettings::ADS_TXT_MODE => trim($input->mode),
                SiteCodeSettings::ADS_TXT_REDIRECT => trim($input->redirectUrl),
            ],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));
    }

    /**
     * Why a redirect address is refused, or null when it may be saved.
     */
    private static function redirectProblem(string $address): ?string
    {
        if ($address === '') {
            return __('Enter a full https address on another host.');
        }

        if (mb_strlen($address) > SiteCodeSettings::ADS_TXT_REDIRECT_MAX) {
            return __('The address is longer than :max characters.', ['max' => SiteCodeSettings::ADS_TXT_REDIRECT_MAX]);
        }

        if (str_starts_with(mb_strtolower($address), 'http://')) {
            return __('Use https://. An http address isn’t allowed.');
        }

        if (preg_match(self::HTTPS_ADDRESS, $address) !== 1) {
            return __('Enter a full https address on another host.');
        }

        $host = parse_url($address, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return __('Enter a full https address on another host.');
        }

        if (mb_strtolower($host) === mb_strtolower((string) parse_url(url('/'), PHP_URL_HOST))) {
            return __('That address is on this site. Choose an ads.txt file on another host.');
        }

        return null;
    }
}
