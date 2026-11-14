<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ChangeBackupScheduleInput;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Changes when the nightly backup starts and how many backups are kept
 * (I-361), on Tools › Backups: an administrator after the password, each
 * value checked by the group's own rules, against the version the form was
 * opened on. The group is sensitive, so the change is logged.
 */
final class ChangeBackupSchedule extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    /** The settings this Operation changes; never the machine key. */
    public const array KEYS = ['hour', 'keep_daily', 'keep_weekly', 'keep_monthly'];

    public function __construct(
        private readonly SettingsWriter $writer,
        private readonly SettingsRegistry $registry,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeBackupScheduleInput $input */
        $group = $this->registry->find(BackupSettings::GROUP) ?? throw new RuntimeException('No settings group [backups] is registered.');

        // SettingRules refuses an unknown key and a secret on its own key, in its own words.
        SettingRules::validateValues($group, $input->values);
    }

    protected function perform(OperationInput $input): int
    {
        /** @var ChangeBackupScheduleInput $input */
        return $this->writer->write(new SettingsWrite(
            group: BackupSettings::GROUP,
            values: array_intersect_key($input->values, array_flip(self::KEYS)),
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId(),
        ));
    }
}
