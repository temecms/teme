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
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Saves the ads.txt text on Settings › Site code: whoever holds
 * site-code.manage, against the version the form was opened on, checked as
 * the group's textarea rules ask.
 */
final class UpdateAdsTxt extends BaseOperation
{
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

        SettingRules::validateValues($group, [SiteCodeSettings::ADS_TXT => $input->adsTxt]);
    }

    protected function perform(OperationInput $input): int
    {
        /** @var UpdateAdsTxtInput $input */
        return $this->writer->write(new SettingsWrite(
            group: SiteCodeSettings::KEY,
            values: [SiteCodeSettings::ADS_TXT => $input->adsTxt],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));
    }
}
