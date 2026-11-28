<?php

/**
 * teme:backup:verify — checks the backups now, as the operator (I-362): a
 * seventh of each place's data read back, and the newest backup restored
 * into the spare database and compared with its manifest. Either, or both.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupMode;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Backups\Verify\TemporaryDatabase;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class RunVerification extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:verify
        {--read-back : Only read back a seventh of each place’s data}
        {--test-restore : Only test-restore the newest backup into the spare database}';

    /** @var string */
    protected $description = 'Check the backups now: read back a seventh of each place’s data, and test-restore the newest backup into the spare database';

    public function handle(VerifyBackups $verify): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $onlyReadBack = (bool) $this->option('read-back');
        $onlyTestRestore = (bool) $this->option('test-restore');
        $both = ! $onlyReadBack && ! $onlyTestRestore;
        $say = function (string $step): void {
            $this->line($step);
        };
        $failed = false;

        try {
            if ($both || $onlyReadBack) {
                $read = $verify->readBack(BackupTrigger::Command, null, $say);
                $read->succeeded ? $this->line('Read-back: done.') : $this->error("Read-back failed: {$read->sentence}");
                $failed = ! $read->succeeded;
            }

            if ($both || $onlyTestRestore) {
                if (BackupMode::isHost() ? ! TemporaryDatabase::configured() : ! SpareDatabase::isNamed()) {
                    // I-2406: no spare database, no test restore; a failure only when it was all that was asked.
                    $this->warn('Test restore skipped: no spare database is named. At the server, name one as TEME_VERIFY_DATABASE.');
                    $failed = $failed || ! $both;
                } else {
                    $tested = $verify->testRestore(BackupTrigger::Command, null, $say);
                    $tested->succeeded ? $this->line('Test restore: done.') : $this->error("Test restore failed: {$tested->sentence}");
                    $failed = $failed || ! $tested->succeeded;
                }
            }
        } catch (BackupAlreadyRunning $running) {
            $this->error($running->getMessage());

            return self::FAILURE;
        } catch (ValidationException $refused) {
            foreach ($refused->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
