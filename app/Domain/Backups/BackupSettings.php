<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `backups` settings group (I-361): the hour the nightly backup starts,
 * how many daily, weekly and monthly backups are kept, and the machine key
 * Teme's own runs open the backups with. Changed on its own screen only:
 * it is never a Settings page, and the generic settings form refuses it.
 */
final class BackupSettings
{
    public const string GROUP = 'backups';

    /** The route of the screen these settings are changed on: Tools › Backups (I-364). */
    public const string SCREEN = 'tools.backups.index';

    /**
     * The group, as its provider registers it.
     */
    public static function group(): SettingsGroup
    {
        // "HH:00", never a bare number: a key with a colon stays a string in a PHP array.
        $hours = [];

        foreach (range(0, 23) as $hour) {
            $time = sprintf('%02d:00', $hour);
            $hours[$time] = $time;
        }

        return new SettingsGroup(
            key: self::GROUP,
            label: 'Backups',
            description: 'When the nightly backup starts, how many backups are kept, and the key Teme’s own backups are opened with.',
            section: 'tools',
            ability: 'backups.manage',
            settings: [
                Setting::select('hour', 'Back up each night at', 'The hour the nightly backup starts, in the site’s time zone. Choose one when few people visit.', '0.1.0', $hours)
                    ->default('02:00'),
                Setting::number('keep_daily', 'Daily backups kept', 'A backup from each of this many recent days.', '0.1.0')
                    ->default(7)->min(1)->max(90),
                Setting::number('keep_weekly', 'Weekly backups kept', 'Then one from each of this many weeks before them.', '0.1.0')
                    ->default(4)->min(0)->max(52),
                Setting::number('keep_monthly', 'Monthly backups kept', 'Then one from each of this many months before those.', '0.1.0')
                    ->default(12)->min(0)->max(120),
                Setting::secret('machine_key', 'Machine key', 'The key Teme’s scheduled backups open the backups with, made when backups are set up. Nobody types it. A server can supply it as TEME_BACKUP_MACHINE_KEY instead.', '0.1.0')
                    ->env('TEME_BACKUP_MACHINE_KEY'),
            ],
            since: '0.1.0',
            // Keeping fewer backups removes some: SettingsWriter logs every change.
            sensitive: true,
            screen: self::SCREEN,
        );
    }
}
