<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Cache\PageCache;
use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\DoctorResult;
use App\Domain\Deployment\Health\PendingMigrations;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\Operations\Input\QueueReleaseRebuildsInput;
use App\Domain\Deployment\Operations\QueueReleaseRebuilds;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Brings a site to the release its code is (I-377): the doctor's errors
 * stop it first; then, under the deploy lock, a backup when migrations
 * wait, the migrations, the doctor's deferred checks, the release's
 * rebuilds, the workers' restart and the page cache. Every run ends in a
 * DeployReport.
 */
final readonly class DeploySite
{
    /** How often a deploy asks again for the backup lock while a backup runs, in seconds. */
    public const int BACKUP_POLL_SECONDS = 10;

    public function __construct(
        private Doctor $doctor,
        private DeployLock $lock,
        private PendingMigrations $migrations,
        private BackupsSetUp $backupsSetUp,
        private BackupSite $backups,
        private QueueReleaseRebuilds $rebuilds,
    ) {}

    /**
     * One deploy, and how it ended. $say gets each line for people;
     * $commands gets the nested commands' own output.
     *
     * @param  Closure(string): void  $say
     */
    public function run(int $waitSeconds, Closure $say, OutputInterface $commands): DeployReport
    {
        $report = DeployReport::forDeploy();

        try {
            if (! $this->preflight($report, $say)) {
                return $report;
            }

            return $this->lock->during(
                $waitSeconds,
                function () use ($say): void {
                    $say('Another deploy is running on this database. Waiting for it to finish.');
                },
                fn (?float $waited): DeployReport => $this->underLock($report->lockTaken($waited), $waitSeconds, $say, $commands),
            );
        } catch (DeployBusy $busy) {
            return $report->busy('lock', $busy->getMessage(), $waitSeconds);
        } catch (Throwable $unexpected) {
            report($unexpected);

            return $report->end(DeployOutcome::Failed, DeployReport::UNEXPECTED);
        }
    }

    /**
     * What waits, without the lock and without changing anything (I-377).
     *
     * @param  Closure(string): void  $say
     */
    public function check(Closure $say): DeployReport
    {
        $report = DeployReport::forCheck();

        try {
            $pending = $this->found($report);

            if ($pending === []) {
                return $report->end(DeployOutcome::Current, 'No migrations are waiting: the database already matches this release.');
            }

            $this->sayWaiting($report, $say);

            return $report->end(DeployOutcome::Pending, count($pending) === 1 ? 'Run php artisan teme:deploy to run it.' : 'Run php artisan teme:deploy to run them.');
        } catch (Throwable $unexpected) {
            report($unexpected);

            return $report->end(DeployOutcome::Failed, DeployReport::UNEXPECTED);
        }
    }

    /**
     * The doctor's errors and warnings, in teme:doctor's own form; true to
     * go on, false when an error has ended the report.
     *
     * @param  Closure(string): void  $say
     */
    private function preflight(DeployReport $report, Closure $say): bool
    {
        $say('Checking this server with the doctor.');
        $shown = array_values(array_filter(
            $this->doctor->examine(),
            fn (DoctorResult $result): bool => in_array($result->finding->severity, [Severity::Error, Severity::Warning], true),
        ));
        $errors = count(array_filter($shown, fn (DoctorResult $result): bool => $result->finding->severity === Severity::Error));
        $report->findings = $shown;

        foreach ($shown as $result) {
            $this->sayFinding($result, $say);
        }

        if ($errors > 0) {
            $report->step('preflight', 'failed')->end(DeployOutcome::Refused, $errors === 1
                ? 'Nothing was changed: the doctor found 1 error. Fix it, then run php artisan teme:deploy again.'
                : "Nothing was changed: the doctor found {$errors} errors. Fix them, then run php artisan teme:deploy again.");

            return false;
        }

        $report->step('preflight', 'done');

        return true;
    }

    /**
     * Everything after the lock is taken, in order (I-377).
     *
     * @param  Closure(string): void  $say
     */
    private function underLock(DeployReport $report, int $waitSeconds, Closure $say, OutputInterface $commands): DeployReport
    {
        $pending = $this->found($report);

        if ($pending === []) {
            $say('No migrations are waiting: the database already matches this release.');
            $report->backupState('not-needed')->step('backup', 'skipped')->step('migrate', 'skipped');

            return $this->finish($report, $say, $commands);
        }

        $this->sayWaiting($report, $say);

        // A release the one running now can't work beside runs only in maintenance mode (I-383).
        if ($report->needsMaintenance !== [] && ! app()->isDownForMaintenance()) {
            return $report->end(DeployOutcome::Refused, 'Nothing was changed. Run php artisan down, then php artisan teme:deploy again, and php artisan up once the new release is serving.');
        }

        if (! $this->backUp($report, $waitSeconds, $say) || ! $this->migrate($report, $pending, $say, $commands)) {
            return $report;
        }

        return $this->finish($report, $say, $commands);
    }

    /**
     * Whether the database is new, the migrations waiting, and those that
     * need maintenance mode, a new database's none.
     *
     * @return list<string>
     */
    private function found(DeployReport $report): array
    {
        $new = $this->migrations->databaseIsNew();
        $pending = $this->migrations->names();
        $report->migrationsFound($new, $pending, $new ? [] : $this->migrations->breakingPreviousRelease($pending));

        return $pending;
    }

    /**
     * @param  Closure(string): void  $say
     */
    private function sayWaiting(DeployReport $report, Closure $say): void
    {
        $count = count($report->pending);
        $say($count === 1 ? '1 migration is waiting:' : "{$count} migrations are waiting:");

        foreach ($report->pending as $name) {
            $say("  {$name}");
        }

        foreach ($report->needsMaintenance as $name) {
            $say("{$name} changes the database in a way the release running now can't work with, so it runs only while the site is in maintenance mode.");
        }
    }

    /**
     * The backup before migrating (I-361). True to go on: backed up, or
     * backups aren't set up; false when the report has ended.
     *
     * @param  Closure(string): void  $say
     */
    private function backUp(DeployReport $report, int $waitSeconds, Closure $say): bool
    {
        // A new database has no backup tables to ask, and nothing to lose.
        if ($report->newDatabase || ! $this->backupsSetUp->now()) {
            $say("Backups aren't set up on this site, so nothing was backed up before migrating. To set them up: php artisan teme:backup:setup.");
            $report->backupState('not-set-up')->step('backup', 'skipped');

            return true;
        }

        $say('Backing up before migrating.');
        $deadline = $waitSeconds > 0 ? CarbonImmutable::now()->addSeconds($waitSeconds) : null;
        $told = false;
        $result = null;

        while ($result === null) {
            try {
                $result = $this->backups->run(BackupTrigger::Deploy, null, function (string $step) use ($say): void {
                    $say("  {$step}");
                });
            } catch (BackupAlreadyRunning) {
                if ($deadline !== null && CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                    $report->busy('backup', DeployBusy::backup($waitSeconds)->getMessage(), $waitSeconds);

                    return false;
                }

                if (! $told) {
                    $say('A backup is already running. Waiting for it to finish, then backing up.');
                    $told = true;
                }

                Sleep::for(self::BACKUP_POLL_SECONDS)->seconds();
            } catch (Throwable $failed) {
                if ($failed instanceof ValidationException) {
                    return $this->backupFailed($report, null, implode(' ', array_map(fn (mixed $sentence): string => (string) $sentence, Arr::flatten($failed->errors()))), $say);
                }

                // BackupSite has ended its run; the deploy's own report says where it stopped.
                report($failed);

                return $this->backupFailed($report, null, 'The backup stopped on an error Teme didn’t expect. The log has its details.', $say);
            }
        }

        return $this->backedUp($report, $result, $say);
    }

    /**
     * @param  Closure(string): void  $say
     */
    private function backedUp(DeployReport $report, BackupResult $result, Closure $say): bool
    {
        if (! $result->succeeded) {
            return $this->backupFailed($report, $result->runId, (string) $result->sentence, $say);
        }

        $say("Backed up to {$result->place}.");

        foreach ($result->problems as $problem) {
            $say("  {$problem}");
        }

        $report->backedUp($result);

        return true;
    }

    /**
     * @param  Closure(string): void  $say
     */
    private function backupFailed(DeployReport $report, ?string $runId, string $sentence, Closure $say): bool
    {
        $say("The backup failed: {$sentence}");
        $report->backupFailed($runId, $sentence)->end(DeployOutcome::BackupFailed, 'Nothing was changed: the backup must succeed before Teme migrates. Fix what it says, then run php artisan teme:deploy again.');

        return false;
    }

    /**
     * migrate --force; true when every waiting migration ran.
     *
     * @param  list<string>  $pending
     * @param  Closure(string): void  $say
     */
    private function migrate(DeployReport $report, array $pending, Closure $say, OutputInterface $commands): bool
    {
        // A lost session lost the lock with it: another deploy may be migrating now (I-377).
        if (! $this->lock->stillHeld()) {
            $report->end(DeployOutcome::Failed, 'The deploy lost its lock before migrating, so nothing was migrated: another deploy may have started. Run php artisan teme:deploy again.');

            return false;
        }

        $say('Migrating.');

        try {
            $status = Artisan::call('migrate', ['--force' => true], $commands);
            $error = $status === 0 ? null : "php artisan migrate ended with exit code {$status}.";
        } catch (Throwable $failed) {
            report($failed);
            $error = mb_strimwidth($failed->getMessage(), 0, 1000, '…');
        }

        $report->ran(array_values(array_diff($pending, $this->migrations->names())));

        if ($error === null) {
            $report->step('migrate', 'done');

            return true;
        }

        $say("A migration failed: {$error}");
        $backup = $report->backupRunId();
        $restore = $backup === null ? '' : ", or restore the backup taken just now with php artisan teme:backup:restore {$backup}";
        $report->migrationFailed($error)->end(DeployOutcome::MigrationFailed, "The migrations before it have run and the rest haven't. Fix what stopped it, then run php artisan teme:deploy again{$restore}.");

        return false;
    }

    /**
     * The steps after migrating, in order, each safe to run again; the first
     * that fails ends the report as unfinished.
     *
     * @param  Closure(string): void  $say
     */
    private function finish(DeployReport $report, Closure $say, OutputInterface $commands): DeployReport
    {
        if (! $this->recheck($report, $say)) {
            return $report;
        }

        /** @var array<string, array{0: string, 1: Closure(): bool}> $steps */
        $steps = [
            'rebuilds' => ["Queueing this release's rebuilds", function () use ($report, $say): bool {
                /** @var list<string> $queued */
                $queued = $this->rebuilds->execute(new QueueReleaseRebuildsInput);
                $report->rebuilds = $queued;

                if ($queued === []) {
                    return false;
                }

                $say("Queued this release's rebuilds, to run in the background: ".implode(', ', $queued).'.');

                return true;
            }],
            'queue_restart' => ['Restarting the queue workers', function () use ($say, $commands): bool {
                $say('Telling the queue workers to restart with this release.');
                $this->call('queue:restart', [], $commands);

                return true;
            }],
            'cache_clear' => ['Clearing the page cache', function () use ($say, $commands): bool {
                $say('Clearing the page cache.');
                $this->call('teme:cache:clear', [], $commands);

                return true;
            }],
            'cache_warm' => ['Warming the page cache', function () use ($say, $commands): bool {
                $skip = match (true) {
                    ! PageCache::enabled() => "The page cache is off, so it isn't warmed.",
                    ! (bool) config('page_cache.warm.enabled') => "Warming is off (PAGE_CACHE_WARM=false), so the page cache isn't warmed.",
                    app()->isDownForMaintenance() => "The site is in maintenance mode, so the page cache isn't warmed.",
                    default => null,
                };

                if ($skip !== null) {
                    $say($skip);

                    return false;
                }

                $say('Warming the page cache.');
                $this->call('teme:cache:warm', [], $commands);

                return true;
            }],
        ];

        foreach ($steps as $step => [$what, $work]) {
            try {
                $report->step($step, $work() ? 'done' : 'skipped');
            } catch (Throwable $failed) {
                report($failed);
                $report->step($step, 'failed');

                return $report->end(DeployOutcome::Unfinished, "{$what} failed, so the deploy stopped there. The database already matches this release: run php artisan teme:deploy again to finish. The log has the details.");
            }
        }

        $ran = count($report->ran);

        return $report->end(DeployOutcome::Deployed, match ($ran) {
            0 => 'Deployed: no migration was waiting.',
            1 => 'Deployed: 1 migration ran.',
            default => "Deployed: {$ran} migrations ran.",
        });
    }

    /**
     * The doctor's checks that couldn't run before migrating, run again now
     * that nothing waits (I-377): true to go on; false when one still can't,
     * and the report has ended unfinished.
     *
     * @param  Closure(string): void  $say
     */
    private function recheck(DeployReport $report, Closure $say): bool
    {
        $deferred = array_filter($report->findings, fn (DoctorResult $result): bool => $result->finding->message === Doctor::NOT_YET);
        $keys = array_values(array_unique(array_map(fn (DoctorResult $result): string => $result->key, $deferred)));

        if ($keys === []) {
            $report->step('recheck', 'skipped');

            return true;
        }

        $say("Checking again what the doctor couldn't check before migrating.");
        $errors = array_values(array_filter(
            $this->doctor->examineOnly($keys),
            fn (DoctorResult $result): bool => $result->finding->severity === Severity::Error,
        ));

        if ($errors === []) {
            $report->step('recheck', 'done');

            return true;
        }

        foreach ($errors as $result) {
            $this->sayFinding($result, $say);
        }

        $report->step('recheck', 'failed')->end(DeployOutcome::Unfinished, "{$errors[0]->title} still couldn't run after migrating, so the deploy stopped there. The database already matches this release: fix what php artisan teme:doctor says, then run php artisan teme:deploy again.");

        return false;
    }

    /**
     * One finding in teme:doctor's own form.
     *
     * @param  Closure(string): void  $say
     */
    private function sayFinding(DoctorResult $result, Closure $say): void
    {
        $say('  '.str_pad($result->finding->severity->label(), 8)." {$result->title}: {$result->finding->message}");

        if ($result->finding->fix !== null) {
            $say(str_repeat(' ', 11)."Fix: {$result->finding->fix}");
        }
    }

    /**
     * Runs a nested command into $output.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws RuntimeException when it doesn't end with 0
     */
    private function call(string $command, array $arguments, OutputInterface $output): void
    {
        $status = Artisan::call($command, $arguments, $output);

        if ($status !== 0) {
            throw new RuntimeException("php artisan {$command} ended with exit code {$status}.");
        }
    }
}
