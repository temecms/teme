<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Operations\Input\RaiseBackupAlertInput;
use App\Domain\Backups\Operations\RaiseBackupAlert;
use App\Domain\Backups\PruneBackups;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\DueBackupWork;
use App\Domain\Backups\Queries\DueVerification;
use App\Domain\Backups\Tracking\TrackedBackup;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * teme:backup:due — starts, as the operator, whatever backup work is due
 * (I-361): a backup someone put in line, or the nightly backup, each as a
 * tracked job, then the weekly prune, the nightly read-back and the weekly
 * test restore, and emails the administrators when the backups' verdict calls
 * for it (I-2409). Runs every minute; while the backup lock is held it does
 * nothing, and asks again next minute.
 */
final class RunDueBackups extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:due';

    /** @var string */
    protected $description = 'Start the nightly backup and read-back, and the weekly prune and test restore, when they’re due';

    public function handle(DueBackupWork $due, TrackedBackup $tracked, BackupJobs $jobs, PruneBackups $prune, DueVerification $checks, VerifyBackups $verify, RaiseBackupAlert $alerts, BackupLock $lock): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        // Told at once, even while a long run holds the lock (I-2409).
        $this->raiseAlert($alerts);

        // A backup, a prune or a check is running (I-358).
        if ($lock->isHeld()) {
            return self::SUCCESS;
        }

        $failed = false;

        try {
            // Back up now and retried backups first, oldest first; one started after the hour is that night's (I-361).
            $jobId = $jobs->nextWaiting();

            if ($jobId === null && $due->backupDue(CarbonImmutable::now())) {
                $jobId = $tracked->nightly();
            }

            if ($jobId !== null) {
                $failed = ! $this->backUp($tracked, $jobs, $jobId);
            }

            if ($due->pruneDue(CarbonImmutable::now())) {
                $pruned = $prune->run(BackupTrigger::Schedule);
                $pruned->succeeded ? $this->line('Weekly prune: done.') : $this->error("Weekly prune failed: {$pruned->sentence}");
                $failed = $failed || ! $pruned->succeeded;
            }

            if ($checks->readBackDue(CarbonImmutable::now())) {
                $read = $verify->readBack(BackupTrigger::Schedule);
                $read->succeeded ? $this->line('Nightly read-back: done.') : $this->error("Nightly read-back failed: {$read->sentence}");
                $failed = $failed || ! $read->succeeded;
            }

            if ($checks->testRestoreDue(CarbonImmutable::now())) {
                $tested = $verify->testRestore(BackupTrigger::Schedule);
                $tested->succeeded ? $this->line('Weekly test restore: done.') : $this->error("Weekly test restore failed: {$tested->sentence}");
                $failed = $failed || ! $tested->succeeded;
            }
        } catch (BackupAlreadyRunning) {
            // Another process took the lock between the ask and the start; the job waits for the next minute.
        }

        // And after the work, so what it found is told this minute.
        $this->raiseAlert($alerts);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Runs one backup job and prints how it ended; whether everything went well.
     */
    private function backUp(TrackedBackup $tracked, BackupJobs $jobs, string $jobId): bool
    {
        $label = $jobs->label($jobId);
        $result = $tracked->run($jobId);

        if ($result === null) {
            $this->error("{$label} didn’t run: {$jobs->message($jobId)}");

            return false;
        }

        $result->succeeded ? $this->line("{$label}: done.") : $this->error("{$label} failed: {$result->sentence}");

        foreach ($result->problems as $problem) {
            $this->warn($problem);
        }

        return $result->allWell();
    }

    /**
     * Raises the backups' alert if one is due. A failure here is reported
     * and never stops a backup: the next minute asks again.
     */
    private function raiseAlert(RaiseBackupAlert $alerts): void
    {
        try {
            $alerts->execute(new RaiseBackupAlertInput);
        } catch (Throwable $failed) {
            report($failed);
        }
    }
}
