<?php

declare(strict_types=1);

namespace App\Domain\Media\Jobs;

use App\Domain\Backups\BackupLock;
use App\Domain\Media\MediaDisks;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes files a committed change no longer needs, by the paths the row
 * recorded — never by listing a folder. It changes no database state. While
 * a backup holds its lock it waits, so a backup that read these files' rows
 * still finds them (I-358); it is never lost to the wait.
 */
final class DeleteMediaFiles implements ShouldQueue
{
    use Queueable;

    /** Seconds between asks while a backup runs. */
    public const int WAIT_SECONDS = 60;

    /** Real failures before the deletion gives up; waiting for a backup is never one. */
    public int $maxExceptions = 5;

    /** When this copy stops waiting, as a Unix time: a day after it was made. */
    public readonly int $until;

    /**
     * @param  array<string, list<string>>  $pathsByDisk  keyed public and originals
     */
    public function __construct(public readonly array $pathsByDisk)
    {
        $this->until = now()->addDay()->getTimestamp();
    }

    /**
     * How long this copy may wait for backups: a day, longer than a first
     * backup of a large site, so a takedown's file still goes the moment the
     * backup ends.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->until);
    }

    public function handle(BackupLock $lock): void
    {
        if ($lock->isHeld()) {
            // The last wait this copy has: a fresh copy waits on, so the deletion is never lost (I-358).
            if (now()->addSeconds(self::WAIT_SECONDS)->getTimestamp() >= $this->until) {
                self::dispatch($this->pathsByDisk)->delay(self::WAIT_SECONDS);
                $this->delete();

                return;
            }

            $this->release(self::WAIT_SECONDS);

            return;
        }

        foreach ($this->pathsByDisk as $disk => $paths) {
            if ($paths !== []) {
                ($disk === 'originals' ? MediaDisks::originals() : MediaDisks::public())->delete($paths);
            }
        }
    }
}
