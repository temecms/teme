<?php

/**
 * Emails one backup alert to every administrator (I-2409): everyone who
 * holds backups.manage, verified and not suspended, read when it runs. No
 * one can turn it off: a backup is a guarantee. It carries only the
 * alert's ID, calls no Operation and asks no elevation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Jobs;

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\Models\BackupAlert;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Backups\Queries\BackupAdministrators;
use App\Domain\Backups\Queries\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Teme\Contracts\Settings\SettingsReader;

final class SendBackupAlertEmails implements ShouldQueue
{
    use Queueable;

    /** A job that failed after some recipients would email them again: it runs once. */
    public int $tries = 1;

    public function __construct(public readonly string $alertId) {}

    public function handle(BackupStatus $status, SettingsReader $settings, BackupAdministrators $administrators): void
    {
        $alert = BackupAlert::query()->find($this->alertId);

        if ($alert === null) {
            return;
        }

        $zone = $settings->string('general', 'timezone');
        $when = fn (?CarbonImmutable $moment): ?string => $moment?->setTimezone($zone)->format('j F Y \a\t H:i');
        // The run of alerts this one belongs to, oldest first: since the fix before it.
        $runStart = BackupAlert::query()
            ->where('kind', BackupAlertKind::Fixed->value)
            ->where('raised_at', '<', $alert->raised_at)
            ->orderByDesc('raised_at')
            ->value('raised_at');
        $runQuery = BackupAlert::query()
            ->where('kind', '<>', BackupAlertKind::Fixed->value)
            ->where('raised_at', '<=', $alert->raised_at)
            ->orderBy('raised_at');

        if ($runStart !== null) {
            $runQuery->where('raised_at', '>', $runStart);
        }

        $run = $runQuery->get();
        // A reminder: since its failure was first told. A fix: since the run began.
        $since = match ($alert->kind) {
            BackupAlertKind::Failing => null,
            BackupAlertKind::Reminder => $run->first(fn (BackupAlert $earlier): bool => in_array($alert->problems[0] ?? '', $earlier->problems, true))?->raised_at,
            BackupAlertKind::Fixed => $run->first()?->raised_at,
        };
        $notice = new BackupAlertNotice($alert->kind, $alert->problems, $settings->string('general', 'site_title'), $when($status->verdict()->lastGoodBackup), $when($since));

        $people = $administrators->all();

        foreach ($people as $person) {
            $person->notifyNow($notice);
        }
    }
}
