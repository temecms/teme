<?php

/**
 * teme:backup:run — backs the site up now, inline, as the operator
 * (docs/architecture/operations.md): the database, the media, the APP_KEY and
 * the manifest at the first place that answers, saying each step.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RunBackup extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:run';

    /** @var string */
    protected $description = 'Back the site up now: the database, the media and the manifest, at every place backups are kept';

    public function handle(BackupSite $site): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $result = $site->run(BackupTrigger::Command, null, fn (string $step) => $this->line($step));
        } catch (BackupAlreadyRunning $running) {
            $this->error($running->getMessage());

            return self::FAILURE;
        } catch (Throwable $refused) {
            // Setting up not finished: StartBackupRun's validation, which reaches here through the container.
            if (! $refused instanceof ValidationException) {
                throw $refused;
            }

            foreach (Arr::flatten($refused->errors()) as $sentence) {
                $this->error((string) $sentence);
            }

            return self::FAILURE;
        }

        if (! $result->succeeded) {
            $this->error((string) $result->sentence);

            return self::FAILURE;
        }

        $this->line($result->mediaFiles === 1
            ? "Backed up to {$result->place}: the database and 1 media file."
            : "Backed up to {$result->place}: the database and ".number_format($result->mediaFiles).' media files.');

        foreach ($result->problems as $problem) {
            $this->warn($problem);
        }

        return $result->allWell() ? self::SUCCESS : self::FAILURE;
    }
}
