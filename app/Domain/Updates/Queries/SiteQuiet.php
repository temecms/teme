<?php

declare(strict_types=1);

namespace App\Domain\Updates\Queries;

use App\Domain\Backups\BackupLock;
use Illuminate\Support\Facades\DB;

/**
 * Whether nothing is in flight that a deploy would cut short or wait on: no backup holds the backup lock and no job is reserved on
 * either queue, both read from Teme's own database (I-3336).
 */
final readonly class SiteQuiet
{
    public function __construct(private BackupLock $backups) {}

    /**
     * Returns true when no backup runs and no job is reserved on the default or the long queue.
     */
    public function isQuiet(): bool
    {
        if ($this->backups->isHeld()) {
            return false;
        }

        foreach (['database', 'database-long'] as $queue) {
            $connection = config("queue.connections.{$queue}.connection");

            if (DB::connection(is_string($connection) ? $connection : null)->table((string) config("queue.connections.{$queue}.table", 'jobs'))->whereNotNull('reserved_at')->exists()) {
                return false;
            }
        }

        return true;
    }
}
