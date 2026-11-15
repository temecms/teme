<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;

/**
 * Tells administrators how the site's backups stand (I-221): red
 * while a backup or a check has failed, none has started for too long, or
 * backups aren't set up; amber while every backup is on this server, none
 * is test-restored, or none has finished yet. Read from the backup tables,
 * never from restic.
 */
final readonly class BackupsSafe implements StatusCheck
{
    public function __construct(private BackupStatus $status) {}

    public function ability(): string
    {
        return 'backups.manage';
    }

    public function label(): string
    {
        return __('Backups');
    }

    public function banner(): ?StatusBanner
    {
        $verdict = $this->status->verdict();

        $action = route(BackupSettings::SCREEN);

        return match ($verdict->level()) {
            BackupVerdict::RED => new StatusBanner('error', $verdict->problems[0], 'See the backups', $action, 90),
            BackupVerdict::AMBER => new StatusBanner('warning', $verdict->cautions[0], 'See the backups', $action, 20),
            default => null,
        };
    }
}
